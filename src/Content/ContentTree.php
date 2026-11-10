<?php

declare(strict_types=1);

namespace IntrAgile\Manual\Content;

/**
 * The language-independent manual tree (`{path}/_meta.php`) plus locale resolution.
 */
final class ContentTree
{
    /**
     * @param  list<string>  $locales
     * @param  list<string>  $fallback
     */
    public function __construct(
        private readonly string $path,
        private readonly array $locales,
        private readonly string $sourceLocale,
        private readonly array $fallback,
    ) {}

    public function path(): string
    {
        return $this->path;
    }

    public function sourceLocale(): string
    {
        return $this->sourceLocale;
    }

    /**
     * The locale a request is served in: the first entry of its fallback chain.
     * That is the requested locale when it is allowed, otherwise the first allowed
     * `fallback` entry, otherwise the source locale.
     */
    public function resolveLocale(string $requested): string
    {
        return $this->chain($requested)[0];
    }

    /**
     * Content fallback chain. An allowed locale is followed by the allowed `fallback`
     * entries and the source locale. A locale that is not allowed is skipped (it never
     * reaches a filesystem path), so the chain starts at the allowed `fallback` entries.
     * Only allowed locales ever appear in it.
     *
     * @return list<string>
     */
    public function chain(string $locale): array
    {
        $allowed = $this->allowedLocales();
        $chain = in_array($locale, $allowed, true) ? [$locale] : [];

        foreach ($this->fallback as $candidate) {
            if (in_array($candidate, $allowed, true)) {
                $chain[] = $candidate;
            }
        }

        $chain[] = $this->sourceLocale;

        return array_values(array_unique($chain));
    }

    /**
     * Sections in `order`. With a locale, each section's chapters are limited to
     * those that have a file in the locale's fallback chain, so numbering,
     * position and navigation only ever count chapters that exist.
     *
     * @return list<array{id: string, icon: string, order: int, chapters: list<string>}>
     */
    public function sections(?string $locale = null): array
    {
        $sections = $this->metaSections();

        if ($locale === null) {
            return $sections;
        }

        foreach ($sections as $i => $section) {
            $sections[$i]['chapters'] = array_values(array_filter(
                $section['chapters'],
                fn (string $chapter): bool => $this->hasFile($section['id'].'/'.$chapter, $locale),
            ));
        }

        return $sections;
    }

    /**
     * @return list<array{id: string, icon: string, order: int, chapters: list<string>}>
     */
    private function metaSections(): array
    {
        $raw = $this->loadArray($this->path.'/_meta.php');
        $sections = [];

        foreach ($raw as $id => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $chapters = [];

            if (isset($definition['chapters']) && is_array($definition['chapters'])) {
                foreach ($definition['chapters'] as $chapter) {
                    if (is_string($chapter) && $chapter !== '') {
                        $chapters[] = $chapter;
                    }
                }
            }

            $icon = $definition['icon'] ?? '';
            $order = $definition['order'] ?? 0;

            $sections[] = [
                'id' => (string) $id,
                'icon' => is_string($icon) ? $icon : '',
                'order' => is_int($order) ? $order : 0,
                'chapters' => $chapters,
            ];
        }

        usort($sections, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return $sections;
    }

    /**
     * @return list<string> `section/chapter` slugs in tree order
     */
    public function orderedSlugs(?string $locale = null): array
    {
        $slugs = [];

        foreach ($this->sections($locale) as $section) {
            foreach ($section['chapters'] as $chapter) {
                $slugs[] = $section['id'].'/'.$chapter;
            }
        }

        return $slugs;
    }

    /**
     * Chapter number as `section.chapter`, e.g. "2.1"; empty for an unknown slug.
     */
    public function number(string $slug, ?string $locale = null): string
    {
        foreach ($this->sections($locale) as $sectionIndex => $section) {
            foreach ($section['chapters'] as $chapterIndex => $chapter) {
                if ($slug === $section['id'].'/'.$chapter) {
                    return ($sectionIndex + 1).'.'.($chapterIndex + 1);
                }
            }
        }

        return '';
    }

    /**
     * Membership in the `_meta.php` tree. This is the only gate before a
     * filesystem path is built from a slug.
     */
    public function isKnown(string $slug): bool
    {
        return in_array($slug, $this->orderedSlugs(), true);
    }

    /**
     * Whether a `_meta` slug has a file in any chain locale. Only call with
     * slugs taken from `_meta.php`.
     */
    private function hasFile(string $slug, string $locale): bool
    {
        foreach ($this->chain($locale) as $candidate) {
            if (is_file($this->path.'/'.$candidate.'/'.$slug.'.md')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Localized section title from `{path}/{locale}/_sections.php`, following the fallback chain.
     */
    public function sectionTitle(string $id, string $locale): ?string
    {
        foreach ($this->chain($locale) as $candidate) {
            $titles = $this->loadArray($this->path.'/'.$candidate.'/_sections.php');
            $title = $titles[$id] ?? null;

            if (is_string($title) && $title !== '') {
                return $title;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function allowedLocales(): array
    {
        return array_values(array_unique([...$this->locales, $this->sourceLocale]));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function loadArray(string $file): array
    {
        if (! is_file($file)) {
            return [];
        }

        $value = (static fn (): mixed => require $file)();

        return is_array($value) ? $value : [];
    }
}
