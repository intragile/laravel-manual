<?php

declare(strict_types=1);

namespace IntrAgile\Manual\Content;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use IntrAgile\Manual\Rendering\ManualRenderer;

/**
 * Serves the manual tree and chapters, with locale fallback and caching.
 *
 * The cache only ever holds scalars and arrays (never objects), so it survives
 * `cache.serializable_classes => false`. Entries of an unexpected shape are
 * treated as a miss and rebuilt.
 *
 * @phpstan-type TreeChapter array{slug: string, number: string, title: string, summary: string}
 * @phpstan-type TreeSection array{id: string, icon: string, title: string, chapters: list<TreeChapter>}
 * @phpstan-type Neighbour array{slug: string, title: string}
 * @phpstan-type Heading array{anchor: string, text: string, level: int}
 * @phpstan-type Chapter array{
 *     slug: string, section: string, number: string, title: string, summary: string, html: string,
 *     headings: list<Heading>, prev: Neighbour|null, next: Neighbour|null, position: int, total: int,
 *     readingMinutes: int, locale: string, fallback: bool
 * }
 *
 * @internal
 */
final class ManualRepository
{
    /**
     * Bump when the shape or the content of cached values changes (2: snippet text in 1.0.1;
     * 3: hyphens inside words kept in 1.0.2).
     */
    public const CACHE_VERSION = 3;

    /** One day, in seconds. */
    private const CACHE_TTL = 86400;

    /** Generation read from the cache once per instance; null until first needed. */
    private ?int $generation = null;

    /** Content fingerprint, computed once per instance. */
    private ?string $fingerprint = null;

    public function __construct(
        private readonly ContentTree $tree,
        private readonly ManualRenderer $renderer,
        private readonly CacheRepository $cache,
        private readonly string $cachePrefix,
        private readonly string $configHash = '',
    ) {}

    /**
     * @return list<TreeSection>
     */
    public function tree(string $locale): array
    {
        $effective = $this->tree->resolveLocale($locale);
        $key = $this->cacheKey('tree', $effective);

        $cached = $this->cache->get($key);

        if ($this->isTree($cached)) {
            return $cached;
        }

        $result = $this->buildTree($effective);
        $this->cache->put($key, $result, self::CACHE_TTL);

        return $result;
    }

    /**
     * @return Chapter|null
     */
    public function chapter(string $slug, string $locale): ?array
    {
        if (! $this->tree->isKnown($slug)) {
            return null;
        }

        $effective = $this->tree->resolveLocale($locale);
        $key = $this->cacheKey('chapter', $effective, $slug);

        $cached = $this->cache->get($key);

        if ($this->isChapter($cached)) {
            return $this->withFallback($cached, $locale);
        }

        $result = $this->buildChapter($slug, $effective);

        if ($result === null) {
            return null;
        }

        $this->cache->put($key, $result, self::CACHE_TTL);

        return $this->withFallback($result, $locale);
    }

    /**
     * @return array{markdown: string, locale: string}|null
     */
    public function markdown(string $slug, string $locale): ?array
    {
        if (! $this->tree->isKnown($slug)) {
            return null;
        }

        foreach ($this->tree->chain($locale) as $candidate) {
            $file = $this->tree->path().'/'.$candidate.'/'.$slug.'.md';

            if (is_file($file)) {
                // An unreadable file fails loudly (the warning becomes an exception).
                return ['markdown' => (string) file_get_contents($file), 'locale' => $candidate];
            }
        }

        return null;
    }

    /**
     * Invalidates every cached tree, chapter and search index on any cache store by
     * moving to a new cache generation (old entries simply expire). Returns the new
     * generation.
     */
    public function flushCache(): int
    {
        $key = $this->generationKey();
        $generation = $this->cache->increment($key);

        // Stores whose increment() does not create a missing key return false.
        if (! is_int($generation) || $generation < 1) {
            $generation = $this->storedGeneration() + 1;
            $this->cache->forever($key, $generation);
        }

        return $this->generation = $generation;
    }

    /**
     * The current cache generation, read once per instance.
     *
     * @internal
     */
    public function generation(): int
    {
        return $this->generation ??= $this->storedGeneration();
    }

    /**
     * @internal
     */
    public function cacheKey(string $kind, string $locale, string $slug = ''): string
    {
        return sprintf(
            '%s:v%d:g%d:%s:%s:%s:%s%s',
            $this->cachePrefix,
            self::CACHE_VERSION,
            $this->generation(),
            $kind,
            $locale,
            $this->configHash,
            $this->fingerprint(),
            $slug === '' ? '' : ':'.$slug,
        );
    }

    private function generationKey(): string
    {
        return $this->cachePrefix.':generation';
    }

    private function storedGeneration(): int
    {
        $value = $this->cache->get($this->generationKey());

        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : 0;
    }

    /**
     * Hash of every content file's `path|mtime|size`; empty directory (or a
     * missing one) yields a stable hash of nothing.
     *
     * @internal
     */
    public function fingerprint(): string
    {
        return $this->fingerprint ??= $this->computeFingerprint();
    }

    private function computeFingerprint(): string
    {
        $root = $this->tree->path();
        $entries = [];

        if (is_dir($root)) {
            // Symlinks are followed, but never into a directory already visited: a link to the
            // root or an ancestor would otherwise recurse until the path or memory runs out.
            $visited = [(string) realpath($root) => true];
            $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS),
                static function (\SplFileInfo $file) use (&$visited): bool {
                    if (! $file->isDir()) {
                        return true;
                    }

                    $real = $file->getRealPath();

                    if ($real === false || isset($visited[$real])) {
                        return false;
                    }

                    return $visited[$real] = true;
                },
            ));

            foreach ($iterator as $file) {
                if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                    continue;
                }

                if (! in_array($file->getExtension(), ['md', 'php'], true)) {
                    continue;
                }

                $entries[] = substr($file->getPathname(), strlen($root)).'|'.$file->getMTime().'|'.$file->getSize();
            }
        }

        sort($entries);

        return sha1(implode("\n", $entries));
    }

    /**
     * @return list<TreeSection>
     */
    private function buildTree(string $locale): array
    {
        $tree = [];

        foreach ($this->tree->sections($locale) as $sectionIndex => $section) {
            $chapters = [];

            foreach ($section['chapters'] as $chapterIndex => $chapter) {
                $slug = $section['id'].'/'.$chapter;
                $source = $this->markdown($slug, $locale);

                if ($source === null) {
                    continue;
                }

                $chapters[] = [
                    'slug' => $slug,
                    'number' => ($sectionIndex + 1).'.'.($chapterIndex + 1),
                    'title' => $this->renderer->title($source['markdown']),
                    'summary' => $this->renderer->summary($source['markdown']),
                ];
            }

            $tree[] = [
                'id' => $section['id'],
                'icon' => $section['icon'],
                'title' => $this->tree->sectionTitle($section['id'], $locale) ?? $section['id'],
                'chapters' => $chapters,
            ];
        }

        return $tree;
    }

    /**
     * @return Chapter|null
     */
    private function buildChapter(string $slug, string $locale): ?array
    {
        $source = $this->markdown($slug, $locale);

        if ($source === null) {
            return null;
        }

        $markdown = $source['markdown'];
        $ordered = $this->tree->orderedSlugs($locale);
        $index = array_search($slug, $ordered, true);

        if ($index === false) {
            return null;
        }

        $words = preg_match_all('/[\p{L}\p{N}]+/u', $this->renderer->plainText($markdown));

        return [
            'slug' => $slug,
            'section' => explode('/', $slug)[0],
            'number' => $this->tree->number($slug, $locale),
            'title' => $this->renderer->title($markdown),
            'summary' => $this->renderer->summary($markdown),
            'html' => $this->renderer->toHtml($markdown, $source['locale']),
            'headings' => $this->renderer->headings($markdown),
            'prev' => $this->neighbour($ordered[$index - 1] ?? null, $locale),
            'next' => $this->neighbour($ordered[$index + 1] ?? null, $locale),
            'position' => $index + 1,
            'total' => count($ordered),
            'readingMinutes' => max(1, (int) ceil(((int) $words) / 200)),
            'locale' => $source['locale'],
            'fallback' => $source['locale'] !== $locale,
        ];
    }

    /**
     * @return Neighbour|null
     */
    private function neighbour(?string $slug, string $locale): ?array
    {
        if ($slug === null) {
            return null;
        }

        $source = $this->markdown($slug, $locale);

        return $source === null ? null : ['slug' => $slug, 'title' => $this->renderer->title($source['markdown'])];
    }

    /**
     * `fallback` is relative to the locale the caller asked for, which may
     * differ from the (allowlisted) locale the entry was cached under.
     *
     * @param  Chapter  $chapter
     * @return Chapter
     */
    private function withFallback(array $chapter, string $requested): array
    {
        $chapter['fallback'] = $chapter['locale'] !== $requested;

        return $chapter;
    }

    /**
     * @phpstan-assert-if-true list<TreeSection> $value
     */
    private function isTree(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $section) {
            if (! is_array($section)
                || ! is_string($section['id'] ?? null)
                || ! is_string($section['icon'] ?? null)
                || ! is_string($section['title'] ?? null)
                || ! is_array($section['chapters'] ?? null)
                || ! array_is_list($section['chapters'])) {
                return false;
            }

            foreach ($section['chapters'] as $chapter) {
                if (! is_array($chapter)
                    || ! is_string($chapter['slug'] ?? null)
                    || ! is_string($chapter['number'] ?? null)
                    || ! is_string($chapter['title'] ?? null)
                    || ! is_string($chapter['summary'] ?? null)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @phpstan-assert-if-true Chapter $value
     */
    private function isChapter(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        foreach (['slug', 'section', 'number', 'title', 'summary', 'html', 'locale'] as $field) {
            if (! is_string($value[$field] ?? null)) {
                return false;
            }
        }

        foreach (['position', 'total', 'readingMinutes'] as $field) {
            if (! is_int($value[$field] ?? null)) {
                return false;
            }
        }

        if (! is_bool($value['fallback'] ?? null) || ! is_array($value['headings'] ?? null)) {
            return false;
        }

        foreach ($value['headings'] as $heading) {
            if (! is_array($heading)
                || ! is_string($heading['anchor'] ?? null)
                || ! is_string($heading['text'] ?? null)
                || ! is_int($heading['level'] ?? null)) {
                return false;
            }
        }

        foreach (['prev', 'next'] as $field) {
            if (! array_key_exists($field, $value)) {
                return false;
            }

            $neighbour = $value[$field];

            if ($neighbour === null) {
                continue;
            }

            if (! is_array($neighbour) || ! is_string($neighbour['slug'] ?? null) || ! is_string($neighbour['title'] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
