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
     * Allowed locale for filesystem access: the requested one when it is
     * configured, otherwise the source locale.
     */
    public function resolveLocale(string $requested): string
    {
        return in_array($requested, $this->allowedLocales(), true) ? $requested : $this->sourceLocale;
    }

    /**
     * Content fallback chain: locale, then the `fallback` list, then the source locale.
     * Only allowed locales ever appear in it.
     *
     * @return list<string>
     */
    public function chain(string $locale): array
    {
        $allowed = $this->allowedLocales();
        $chain = [$this->resolveLocale($locale)];

        foreach ($this->fallback as $candidate) {
            if (in_array($candidate, $allowed, true)) {
                $chain[] = $candidate;
            }
        }

        $chain[] = $this->sourceLocale;

        return array_values(array_unique($chain));
    }

    /**
     * @return list<array{id: string, icon: string, order: int, chapters: list<string>}>
     */
    public function sections(): array
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
    public function orderedSlugs(): array
    {
        $slugs = [];

        foreach ($this->sections() as $section) {
            foreach ($section['chapters'] as $chapter) {
                $slugs[] = $section['id'].'/'.$chapter;
            }
        }

        return $slugs;
    }

    /**
     * Chapter number as `section.chapter`, e.g. "2.1"; empty for an unknown slug.
     */
    public function number(string $slug): string
    {
        foreach ($this->sections() as $sectionIndex => $section) {
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
