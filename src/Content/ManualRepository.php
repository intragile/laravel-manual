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
 * @phpstan-type Chapter array{slug: string, section: string, number: string, title: string, summary: string, html: string, headings: list<Heading>, prev: Neighbour|null, next: Neighbour|null, position: int, total: int, readingMinutes: int, locale: string, fallback: bool}
 */
final class ManualRepository
{
    /** Bump when the shape of cached values changes. */
    public const CACHE_VERSION = 1;

    public function __construct(
        private readonly ContentTree $tree,
        private readonly ManualRenderer $renderer,
        private readonly CacheRepository $cache,
        private readonly string $cachePrefix,
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
        $this->cache->put($key, $result, now()->addDay());

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

        $this->cache->put($key, $result, now()->addDay());

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
                $contents = file_get_contents($file);

                if ($contents !== false) {
                    return ['markdown' => $contents, 'locale' => $candidate];
                }
            }
        }

        return null;
    }

    /**
     * @internal
     */
    public function cacheKey(string $kind, string $locale, string $slug = ''): string
    {
        return sprintf(
            '%s:v%d:%s:%s:%s%s',
            $this->cachePrefix,
            self::CACHE_VERSION,
            $kind,
            $locale,
            $this->fingerprint(),
            $slug === '' ? '' : ':'.$slug,
        );
    }

    /**
     * Hash of every content file's `path|mtime|size`; empty directory (or a
     * missing one) yields a stable hash of nothing.
     *
     * @internal
     */
    public function fingerprint(): string
    {
        $root = $this->tree->path();
        $entries = [];

        if (is_dir($root)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );

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

        foreach ($this->tree->sections() as $section) {
            $chapters = [];

            foreach ($section['chapters'] as $chapter) {
                $slug = $section['id'].'/'.$chapter;
                $source = $this->markdown($slug, $locale);

                if ($source === null) {
                    continue;
                }

                $chapters[] = [
                    'slug' => $slug,
                    'number' => $this->tree->number($slug),
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
        $ordered = $this->tree->orderedSlugs();
        $index = array_search($slug, $ordered, true);

        if ($index === false) {
            return null;
        }

        $words = preg_match_all('/[\p{L}\p{N}]+/u', $this->renderer->plainText($markdown));

        return [
            'slug' => $slug,
            'section' => explode('/', $slug)[0],
            'number' => $this->tree->number($slug),
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
