<?php

declare(strict_types=1);

namespace IntrAgile\Manual\Search;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use IntrAgile\Manual\Content\ContentTree;
use IntrAgile\Manual\Content\ManualRepository;
use IntrAgile\Manual\Rendering\ManualRenderer;
use IntrAgile\Manual\Support\Ascii;

/**
 * Server-side full-text search. Only hits and a short plain-text snippet ever
 * leave the server, never the content itself.
 *
 * The per-locale index is cached as arrays only (no objects), so it survives
 * `cache.serializable_classes => false`; a wrongly shaped entry is rebuilt.
 *
 * @phpstan-type Document array{
 *     slug: string, title: string, headings: string, summary: string, body: string,
 *     originalTitle: string, originalBody: string
 * }
 * @phpstan-type SearchHit array{slug: string, section: string, title: string, snippet: string, score: int}
 *
 * @internal
 */
final class ManualSearch
{
    private const MAX_QUERY_LENGTH = 200;

    /** One day, in seconds; the same TTL as the repository's tree and chapter entries. */
    private const CACHE_TTL = 86400;

    private const SNIPPET_BEFORE = 70;

    private const SNIPPET_LENGTH = 220;

    private const SNIPPET_FALLBACK = 160;

    /** The most characters a window edge moves to land on a word boundary. */
    private const SNIPPET_MAX_SNAP = 40;

    /**
     * @param  array<string, Stemmer>  $stemmers  locale => stemmer; a missing locale means no stemming
     */
    public function __construct(
        private readonly ContentTree $tree,
        private readonly ManualRepository $repository,
        private readonly ManualRenderer $renderer,
        private readonly CacheRepository $cache,
        private readonly array $stemmers,
        private readonly int $minTermLength,
        private readonly int $defaultLimit,
    ) {}

    /**
     * Every term must occur somewhere in a chapter; results are ordered by
     * weighted hits (title 6, headings 3, summary 2, body 1), best first.
     *
     * @param  int|null  $limit  null uses `manual.search.limit`; clamped to at least 1
     * @return list<SearchHit>
     */
    public function search(string $query, string $locale, ?int $limit = null): array
    {
        $effective = $this->tree->resolveLocale($locale);
        $terms = $this->terms($query, $this->stemmers[$effective] ?? new NullStemmer);

        if ($terms === []) {
            return [];
        }

        $limit = max(1, $limit ?? $this->defaultLimit);
        $matches = [];

        foreach ($this->index($effective) as $document) {
            $score = 0;
            $all = $document['title'].' '.$document['headings'].' '.$document['summary'].' '.$document['body'];
            $matchesAll = true;

            foreach ($terms as $term) {
                if (! str_contains($all, $term)) {
                    $matchesAll = false;
                    break;
                }

                $score += 6 * substr_count($document['title'], $term);
                $score += 3 * substr_count($document['headings'], $term);
                $score += 2 * substr_count($document['summary'], $term);
                $score += substr_count($document['body'], $term);
            }

            if (! $matchesAll || $score === 0) {
                continue;
            }

            $matches[] = ['document' => $document, 'score' => $score];
        }

        // Stable sort (PHP 8): ties keep tree order. Snippets only for what is returned.
        usort($matches, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $results = [];

        foreach (array_slice($matches, 0, $limit) as $match) {
            $document = $match['document'];
            $results[] = [
                'slug' => $document['slug'],
                'section' => explode('/', $document['slug'])[0],
                'title' => $document['originalTitle'],
                'snippet' => $this->snippet($document['originalBody'], $terms),
                'score' => $match['score'],
            ];
        }

        return $results;
    }

    /**
     * @return list<string>
     */
    private function terms(string $query, Stemmer $stemmer): array
    {
        $normalized = self::normalize(mb_substr($query, 0, self::MAX_QUERY_LENGTH));
        $terms = [];

        foreach (preg_split('/[^a-z0-9]+/', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            if (strlen($part) < $this->minTermLength) {
                continue;
            }

            $stem = $stemmer->stem($part);

            if ($stem !== '') {
                $terms[$stem] = $stem;
            }
        }

        return array_values($terms);
    }

    private static function normalize(string $text): string
    {
        return strtolower(Ascii::fold($text));
    }

    /**
     * @return list<Document>
     */
    private function index(string $locale): array
    {
        $key = $this->repository->cacheKey('search', $locale);
        $cached = $this->cache->get($key);

        if ($this->isIndex($cached)) {
            return $cached;
        }

        $index = $this->buildIndex($locale);
        $this->cache->put($key, $index, self::CACHE_TTL);

        return $index;
    }

    /**
     * @return list<Document>
     */
    private function buildIndex(string $locale): array
    {
        $index = [];

        foreach ($this->tree->orderedSlugs($locale) as $slug) {
            $source = $this->repository->markdown($slug, $locale);

            if ($source === null) {
                continue;
            }

            $markdown = $source['markdown'];
            $title = $this->renderer->title($markdown);
            $body = $this->renderer->plainText($markdown);
            $headings = implode(' ', array_column($this->renderer->headings($markdown), 'text'));

            $index[] = [
                'slug' => $slug,
                'title' => self::normalize($title),
                'headings' => self::normalize($headings),
                'summary' => self::normalize($this->renderer->summary($markdown)),
                'body' => self::normalize($body),
                'originalTitle' => $title,
                'originalBody' => $body,
            ];
        }

        return $index;
    }

    /**
     * A plain-text window around the first hit in the original text. Folding can
     * change byte lengths, so each original character gets its own folded form
     * and offsets are mapped back to character positions; the window is cut with
     * mb_* so it can never split a UTF-8 character.
     *
     * @param  list<string>  $terms
     */
    private function snippet(string $original, array $terms): string
    {
        $chars = mb_str_split($original);
        $normalized = '';
        $charAt = [];

        foreach ($chars as $i => $char) {
            $folded = strtolower(Ascii::foldChar($char));
            $normalized .= $folded;

            for ($b = strlen($folded); $b > 0; $b--) {
                $charAt[] = $i;
            }
        }

        $position = null;

        foreach ($terms as $term) {
            $found = strpos($normalized, $term);

            if ($found !== false && ($position === null || $found < $position)) {
                $position = $found;
            }
        }

        $length = count($chars);

        if ($position === null) {
            $end = self::snapEnd($chars, min($length, self::SNIPPET_FALLBACK), 0);

            return trim(implode('', array_slice($chars, 0, $end))).($end < $length ? '…' : '');
        }

        $hit = $charAt[$position];
        $start = self::snapStart($chars, max(0, $hit - self::SNIPPET_BEFORE), $hit);
        $end = self::snapEnd($chars, min($length, $start + self::SNIPPET_LENGTH), $hit);
        $snippet = trim(implode('', array_slice($chars, $start, $end - $start)));

        return ($start > 0 ? '…' : '').$snippet.($end < $length ? '…' : '');
    }

    /**
     * A window start that cuts a word moves forward to just after the next whitespace, unless
     * that would pass the hit or drop more than SNIPPET_MAX_SNAP characters (an overlong
     * "word" such as a URL is cut instead).
     *
     * @param  list<string>  $chars
     */
    private static function snapStart(array $chars, int $start, int $hit): int
    {
        if (! isset($chars[$start - 1], $chars[$start]) || self::isSpace($chars[$start - 1]) || self::isSpace($chars[$start])) {
            return $start;
        }

        for ($i = $start; $i < $hit && $i - $start < self::SNIPPET_MAX_SNAP; $i++) {
            if (self::isSpace($chars[$i])) {
                return $i + 1;
            }
        }

        return $start;
    }

    /**
     * A window end that cuts a word moves back to the last whitespace, under the same limits.
     *
     * @param  list<string>  $chars
     */
    private static function snapEnd(array $chars, int $end, int $hit): int
    {
        if (! isset($chars[$end], $chars[$end - 1]) || self::isSpace($chars[$end - 1]) || self::isSpace($chars[$end])) {
            return $end;
        }

        for ($i = $end - 1; $i > $hit && $end - $i <= self::SNIPPET_MAX_SNAP; $i--) {
            if (self::isSpace($chars[$i])) {
                return $i;
            }
        }

        return $end;
    }

    private static function isSpace(string $char): bool
    {
        return preg_match('/^\s$/u', $char) === 1;
    }

    /**
     * @phpstan-assert-if-true list<Document> $value
     */
    private function isIndex(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $document) {
            if (! is_array($document)) {
                return false;
            }

            foreach (['slug', 'title', 'headings', 'summary', 'body', 'originalTitle', 'originalBody'] as $field) {
                if (! is_string($document[$field] ?? null)) {
                    return false;
                }
            }
        }

        return true;
    }
}
