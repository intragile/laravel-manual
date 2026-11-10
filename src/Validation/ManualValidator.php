<?php

declare(strict_types=1);

namespace IntrAgile\Manual\Validation;

use IntrAgile\Manual\Rendering\ManualRenderer;

/**
 * Content checks behind `php artisan manual:check`.
 *
 * Every finding is an error; there is no warning level. Messages are English and
 * deterministic (sorted), in the form `[locale] section/slug: description`; problems
 * that belong to no single chapter use `_meta.php: ...`, `[locale] _sections.php: ...`,
 * `[locale] section: ...`, `links: ...` or `manual.known_routes: ...`.
 *
 * Malformed `_meta.php` / `_sections.php` files (a non-array, an exception while
 * loading) are reported, never thrown.
 *
 * Markup checks (raw HTML, tokens, headings, `:::` markers) ignore fenced code blocks;
 * raw HTML and token checks also ignore inline code spans, the same way the renderer
 * leaves code alone. Indented (4-space) code blocks are not recognised as code.
 *
 * @internal
 */
final class ManualValidator
{
    private const TOKEN_KINDS = ['manual', 'route', 'link', 'icon'];

    /** Section ids and chapter slugs in `_meta.php`. */
    private const NAME = '/^[A-Za-z0-9_-]+$/';

    /** Stand-in for a line of a fenced code block; trim() keeps it, so it is never blank. */
    private const CODE_LINE = "\x01";

    /**
     * @param  list<string>  $locales  `manual.locales`
     * @param  list<string>  $callouts  allowed `:::type` names
     * @param  array<string, array<string, string>>  $links  link key => locale => URL
     * @param  mixed  $knownRoutes  null (skip the route check) | list<string> | callable(): iterable<string>
     */
    public function __construct(
        private readonly string $path,
        private readonly array $locales,
        private readonly string $sourceLocale,
        private readonly array $callouts,
        private readonly array $links,
        private readonly mixed $knownRoutes,
        private readonly ManualRenderer $renderer,
    ) {}

    /**
     * Every required locale: `manual.locales` plus `source_locale`, source first.
     *
     * @return list<string>
     */
    public function configuredLocales(): array
    {
        return array_values(array_unique([$this->sourceLocale, ...$this->locales]));
    }

    /**
     * @param  list<string>|null  $only  check the source locale plus these (unconfigured ones are
     *                                   ignored); null checks every configured locale
     * @return list<string> sorted error messages; empty means the manual is valid
     */
    public function errors(?array $only = null): array
    {
        $errors = [];
        $sections = $this->meta($errors);

        if ($sections === null) {
            return $this->finish($errors);
        }

        $slugs = [];
        foreach ($sections as $section) {
            foreach ($section['chapters'] as $chapter) {
                $slugs[] = $section['id'].'/'.$chapter;
            }
        }

        if ($slugs === []) {
            $errors[] = '_meta.php: the manual is empty (no chapters listed)';

            return $this->finish($errors);
        }

        $routes = $this->routes($errors);
        $this->checkLinks($errors);

        $locales = $this->configuredLocales();
        if ($only !== null) {
            $locales = array_values(array_unique([$this->sourceLocale, ...array_intersect($only, $locales)]));
        }

        foreach ($locales as $locale) {
            $this->checkSectionTitles($errors, $locale, array_column($sections, 'id'));
            $this->checkOrphans($errors, $locale, $slugs);

            foreach ($slugs as $slug) {
                $markdown = $this->read($locale, $slug);

                if ($markdown === null) {
                    $errors[] = $locale === $this->sourceLocale
                        ? "[{$locale}] {$slug}: missing in source locale"
                        : "[{$locale}] {$slug}: missing translation";

                    continue;
                }

                $this->checkChapter($errors, "[{$locale}] {$slug}: ", $locale, $markdown, $slugs, $routes);
            }
        }

        $this->checkParity($errors, $slugs, array_values(array_diff($locales, [$this->sourceLocale])));

        return $this->finish($errors);
    }

    /**
     * @param  list<string>  $errors
     * @return list<string>
     */
    private function finish(array $errors): array
    {
        $errors = array_values(array_unique($errors));
        sort($errors, SORT_STRING);

        return $errors;
    }

    /**
     * The valid part of `_meta.php`; null when the file is unusable.
     *
     * @param  list<string>  $errors
     * @return list<array{id: string, chapters: list<string>}>|null
     */
    private function meta(array &$errors): ?array
    {
        $raw = $this->load($this->path.'/_meta.php');

        if ($raw === null) {
            $errors[] = '_meta.php: file not found';

            return null;
        }

        if (is_string($raw)) {
            $errors[] = '_meta.php: '.$raw;

            return null;
        }

        $sections = [];

        foreach ($raw as $id => $definition) {
            $id = (string) $id;
            $where = "_meta.php: section \"{$id}\": ";

            if (preg_match(self::NAME, $id) !== 1) {
                $errors[] = $where.'invalid section id (use letters, digits, "-" and "_")';

                continue;
            }

            if (! is_array($definition)) {
                $errors[] = $where.'must be an array';

                continue;
            }

            if (array_key_exists('icon', $definition) && ! is_string($definition['icon'])) {
                $errors[] = $where.'"icon" must be a string';
            }

            if (array_key_exists('order', $definition) && ! is_int($definition['order'])) {
                $errors[] = $where.'"order" must be an integer';
            }

            $list = $definition['chapters'] ?? [];

            if (! is_array($list) || ! array_is_list($list)) {
                $errors[] = $where.'"chapters" must be a list of chapter slugs';

                continue;
            }

            if ($list === []) {
                $errors[] = $where.'has no chapters';
            }

            $chapters = [];

            foreach ($list as $chapter) {
                if (! is_string($chapter) || preg_match(self::NAME, $chapter) !== 1) {
                    $shown = is_string($chapter) ? '"'.$chapter.'"' : get_debug_type($chapter);
                    $errors[] = $where.'invalid chapter slug '.$shown.' (use letters, digits, "-" and "_")';

                    continue;
                }

                if (in_array($chapter, $chapters, true)) {
                    $errors[] = $where.'chapter "'.$chapter.'" is listed more than once';

                    continue;
                }

                $chapters[] = $chapter;
            }

            $sections[] = ['id' => $id, 'chapters' => $chapters];
        }

        return $sections;
    }

    /**
     * The array a PHP file returns; null when the file does not exist; a description of the
     * problem when it cannot be loaded or does not return an array.
     *
     * @return array<array-key, mixed>|string|null
     */
    private function load(string $file): array|string|null
    {
        if (! is_file($file)) {
            return null;
        }

        try {
            $value = (static fn (): mixed => require $file)();
        } catch (\Throwable $e) {
            return 'cannot be loaded ('.$e::class.': '.$e->getMessage().')';
        }

        return is_array($value) ? $value : 'must return an array, returns '.get_debug_type($value);
    }

    /**
     * @param  list<string>  $errors
     * @return list<string>|null null when the route check is off
     */
    private function routes(array &$errors): ?array
    {
        $routes = $this->knownRoutes;

        if ($routes === null) {
            return null;
        }

        if (! is_array($routes) && is_callable($routes)) {
            try {
                $routes = $routes();
            } catch (\Throwable $e) {
                $errors[] = 'manual.known_routes: the callable failed ('.$e::class.': '.$e->getMessage().')';

                return null;
            }
        }

        if ($routes instanceof \Traversable) {
            $routes = iterator_to_array($routes, false);
        }

        if (! is_array($routes)) {
            $errors[] = 'manual.known_routes: must be a list of route names or a callable returning one';

            return null;
        }

        return array_values(array_filter($routes, is_string(...)));
    }

    /**
     * @param  list<string>  $errors
     */
    private function checkLinks(array &$errors): void
    {
        foreach ($this->links as $key => $urls) {
            foreach ($urls as $locale => $url) {
                if (preg_match(ManualRenderer::SAFE_URL, $url) !== 1) {
                    $errors[] = "links: \"{$key}\" ({$locale}): invalid URL (only http, https and mailto URLs are linked)";
                }
            }
        }
    }

    /**
     * @param  list<string>  $errors
     * @param  list<string>  $ids
     */
    private function checkSectionTitles(array &$errors, string $locale, array $ids): void
    {
        $titles = $this->load($this->path.'/'.$locale.'/_sections.php');

        if (is_string($titles)) {
            $errors[] = "[{$locale}] _sections.php: {$titles}";

            return;
        }

        $titles ??= [];

        foreach ($ids as $id) {
            $title = $titles[$id] ?? null;

            if (! is_string($title) || trim($title) === '') {
                $errors[] = "[{$locale}] {$id}: missing section title in _sections.php";
            }
        }

        foreach (array_keys($titles) as $key) {
            if (! in_array((string) $key, $ids, true)) {
                $errors[] = "[{$locale}] _sections.php: unknown section \"{$key}\" (not in _meta.php)";
            }
        }
    }

    /**
     * A Markdown file the tree does not list is unreachable: the API only serves `_meta.php` slugs.
     *
     * @param  list<string>  $errors
     * @param  list<string>  $slugs
     */
    private function checkOrphans(array &$errors, string $locale, array $slugs): void
    {
        $root = $this->path.'/'.$locale;

        if (! is_dir($root)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'md') {
                continue;
            }

            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1, -3));

            if (! in_array($relative, $slugs, true)) {
                $errors[] = "[{$locale}] {$relative}: file is not listed in _meta.php";
            }
        }
    }

    private function read(string $locale, string $slug): ?string
    {
        $file = $this->path.'/'.$locale.'/'.$slug.'.md';

        if (! is_file($file)) {
            return null;
        }

        $contents = file_get_contents($file);

        return $contents === false ? null : $contents;
    }

    /**
     * @param  list<string>  $errors
     * @param  list<string>  $slugs
     * @param  list<string>|null  $routes
     */
    private function checkChapter(array &$errors, string $where, string $locale, string $markdown, array $slugs, ?array $routes): void
    {
        $lines = $this->linesOutsideFences($markdown);

        // --- title, summary, heading levels ---

        $h1 = 0;
        $setext = false;
        $deep = false;

        foreach ($lines as $i => $line) {
            if (preg_match('/^ {0,3}(#{1,6})(?:[ \t]|$)/', $line, $m) === 1) {
                $h1 += strlen($m[1]) === 1 ? 1 : 0;
                $deep = $deep || strlen($m[1]) >= 4;
            } elseif (preg_match('/^ {0,3}=+[ \t]*$/', $line) === 1 && $this->isParagraphText($lines[$i - 1] ?? '')) {
                // Setext H1 (`Title` / `===`). A setext H2 (`---`) is fine: H2 is allowed.
                $h1++;
                $setext = true;
            }
        }

        if ($setext) {
            // CommonMark renders it as an H1, but title() and summary() only read `# Title`.
            $errors[] = $where.'setext H1 heading ("===" underline) is not supported (write "# Title")';
        } elseif ($h1 === 0 || trim($this->renderer->title($markdown)) === '') {
            $errors[] = $where.'missing title (the first "# " heading)';
        }

        if ($h1 > 1) {
            $errors[] = $where.'more than one H1 heading (a chapter has exactly one title)';
        }

        if (trim($this->renderer->summary($markdown)) === '') {
            $errors[] = $where.'missing summary (the first paragraph after the title)';
        }

        if ($deep) {
            $errors[] = $where.'H4 or deeper heading (only H2 and H3 are allowed)';
        }

        // --- ::: callouts ---

        $this->checkCallouts($errors, $where, $lines);

        // --- raw HTML and tokens: prose only ---

        $prose = $this->withoutCodeSpans(implode("\n", $lines));

        if (preg_match($this->htmlPattern(), $prose, $m) === 1) {
            $excerpt = preg_replace('/\s+/', ' ', $m[0]) ?? $m[0];
            $excerpt = mb_strlen($excerpt) > 40 ? mb_substr($excerpt, 0, 40).'…' : $excerpt;
            $errors[] = $where.'raw HTML is not allowed: '.$excerpt;
        }

        $syntaxError = $this->checkTokens($errors, $where, $prose, $slugs, $routes);

        /*
         * The rendered page must not keep an unresolved `[[`. The source checks above miss one
         * case on purpose: inside a GFM table cell an unescaped `|` splits the token into two
         * cells. Code is removed first, it may show `[[` legitimately.
         */
        if (! $syntaxError) {
            $html = $this->renderer->toHtml($markdown, $locale);
            $html = preg_replace('/<(pre|code)\b[^>]*>.*?<\/\1>/s', '', $html) ?? $html;

            if (str_contains($html, '[[')) {
                $errors[] = $where.'unresolved "[[" in the rendered page (inside a table cell write "\|" instead of "|" in a token)';
            }
        }
    }

    /**
     * Whether a line can be the text line of a setext heading: non-blank, not code and not the
     * start of another block.
     */
    private function isParagraphText(string $line): bool
    {
        $trimmed = trim($line);

        return $trimmed !== ''
            && $trimmed !== self::CODE_LINE
            && preg_match('/^(#{1,6}(\s|$)|:::|\||>|[-*+]\s|\d+[.)]\s|=+$)/', $trimmed) !== 1;
    }

    /**
     * @param  list<string>  $errors
     * @param  list<string>  $lines
     */
    private function checkCallouts(array &$errors, string $where, array $lines): void
    {
        $open = 0;
        $unbalanced = false;
        $needsBlank = false;
        $nested = false;

        foreach ($lines as $i => $line) {
            $trimmed = trim($line);

            // The renderer only resolves markers at the top level: an indented marker (list item)
            // or a quoted one stays literal text on the page.
            if (preg_match('/^(?:[ \t]+|[ \t]*>[ \t>]*):::/', $line) === 1) {
                $nested = true;

                continue;
            }

            if (! str_starts_with($trimmed, ':::')) {
                continue;
            }

            $type = trim(substr($trimmed, 3));

            if ($type === '' && $open > 0) {
                $open--;
            } elseif ($type === '') {
                $unbalanced = true;
            } else {
                $callouts = ManualRenderer::validCallouts($this->callouts);

                if (! in_array($type, $callouts, true)) {
                    $allowed = $callouts === [] ? 'none' : implode(', ', $callouts);
                    $errors[] = $where.'unknown callout type ":::'.$type.'" (allowed: '.$allowed.')';
                }

                $open++;
            }

            $before = $lines[$i - 1] ?? '';
            $after = $lines[$i + 1] ?? '';

            if (trim($before) !== '' || trim($after) !== '') {
                $needsBlank = true;
            }
        }

        if ($open !== 0 || $unbalanced) {
            $errors[] = $where.'unbalanced ":::" markers (every ":::type" needs its own closing ":::")';
        }

        if ($needsBlank) {
            $errors[] = $where.'":::" markers need a blank line before and after';
        }

        if ($nested) {
            $errors[] = $where.'callout markers must start at the beginning of the line (not inside a list or quote)';
        }
    }

    /**
     * @param  list<string>  $errors
     * @param  list<string>  $slugs
     * @param  list<string>|null  $routes
     * @return bool whether a token is malformed (it then stays raw in the output)
     */
    private function checkTokens(array &$errors, string $where, string $prose, array $slugs, ?array $routes): bool
    {
        $safe = ManualRenderer::SAFE_VALUE;
        $syntaxError = false;

        preg_match_all('/\[\[(.*?)\]\]/', $prose, $matches);

        foreach ($matches[1] as $inner) {
            // A GFM table cell needs `\|`; the table parser unescapes it before the renderer runs.
            $inner = str_replace('\|', '|', $inner);
            $token = '[['.$inner.']]';

            if (preg_match('/^([A-Za-z][A-Za-z0-9_-]*):/', $inner, $kind) !== 1) {
                $errors[] = $where.'malformed token '.$token;
                $syntaxError = true;

                continue;
            }

            if (! in_array($kind[1], self::TOKEN_KINDS, true)) {
                $errors[] = $where.'unknown token kind "'.$kind[1].'" in '.$token;
                $syntaxError = true;

                continue;
            }

            if ($kind[1] === 'icon') {
                if (preg_match('/^icon:'.$safe.'$/', $inner) !== 1) {
                    $errors[] = $where.'invalid icon name in '.$token.' (allowed: letters, digits, ".", "_", "/" and "-"; no label)';
                    $syntaxError = true;
                }

                continue;
            }

            if (preg_match('/^(?:manual|route|link):('.$safe.')(?:\|([^\]]+))?$/', $inner, $parts) !== 1) {
                $errors[] = $where.'malformed token '.$token.' (expected [['.$kind[1].':name|label]], name: letters, digits, ".", "_", "/" and "-")';
                $syntaxError = true;

                continue;
            }

            $target = $parts[1];

            if ($kind[1] === 'manual' && ! in_array($target, $slugs, true)) {
                $errors[] = $where.'broken manual link "'.$target.'" (not in _meta.php)';
            } elseif ($kind[1] === 'link' && ! isset($this->links[$target])) {
                $errors[] = $where.'unknown link key "'.$target.'" (add it to the links map)';
            } elseif ($kind[1] === 'route' && $routes !== null && ! in_array($target, $routes, true)) {
                $errors[] = $where.'unknown route "'.$target.'" (not in manual.known_routes)';
            }
        }

        return $syntaxError;
    }

    /**
     * Source and translation must have the same block structure: headings, list items, table
     * rows, `:::` markers, code blocks and paragraphs in the same order. Translation-independent
     * (a longer sentence is still one paragraph), it catches a dropped section, table row or list
     * item. It cannot catch a paragraph that was translated shorter.
     *
     * @param  list<string>  $errors
     * @param  list<string>  $slugs
     * @param  list<string>  $translations
     */
    private function checkParity(array &$errors, array $slugs, array $translations): void
    {
        $source = $this->sourceLocale;

        foreach ($slugs as $slug) {
            $sourceMarkdown = $this->read($source, $slug);

            if ($sourceMarkdown === null) {
                continue; // reported as missing in source locale
            }

            $expected = $this->blockShape($sourceMarkdown);

            foreach ($translations as $locale) {
                $markdown = $this->read($locale, $slug);

                if ($markdown === null) {
                    continue; // reported as missing translation
                }

                $actual = $this->blockShape($markdown);

                if ($actual === $expected) {
                    continue;
                }

                $errors[] = sprintf(
                    '[%s] %s: block structure differs from %s (%d blocks instead of %d); %s',
                    $locale,
                    $slug,
                    $source,
                    count($actual),
                    count($expected),
                    $this->firstDivergence($expected, $actual, $source, $locale),
                );
            }
        }
    }

    /**
     * One label per block. Consecutive paragraph lines (and a paragraph line continuing a list
     * item or quote) are one block, so a different line wrap is not a difference.
     *
     * @return list<string>
     */
    private function blockShape(string $markdown): array
    {
        $shape = [];
        $previous = null;
        $afterBlank = true;

        foreach ($this->linesOutsideFences($markdown) as $line) {
            $line = trim($line);

            if ($line === '') {
                $afterBlank = true;

                continue;
            }

            $label = match (true) {
                $line === self::CODE_LINE => 'code block',
                $line === ':::' => 'callout end',
                str_starts_with($line, ':::') => 'callout '.trim(substr($line, 3)),
                preg_match('/^(#{1,6})(?:\s|$)/', $line, $m) === 1 => 'H'.strlen($m[1]).' heading',
                str_starts_with($line, '|') => 'table row',
                str_starts_with($line, '>') => 'quote',
                preg_match('/^[-*+]\s/', $line) === 1 => 'list item',
                preg_match('/^\d+[.)]\s/', $line) === 1 => 'numbered item',
                default => 'paragraph',
            };

            $continues = ! $afterBlank && match ($label) {
                'code block', 'quote' => $previous === $label,
                'paragraph' => in_array($previous, ['paragraph', 'quote', 'list item', 'numbered item'], true),
                // CommonMark: only an ordered list starting at 1 can interrupt a paragraph, so a
                // wrapped "2026. november ..." line is still paragraph text.
                'numbered item' => $previous === 'paragraph' && preg_match('/^0*1[.)]\s/', $line) !== 1,
                default => false,
            };

            if (! $continues) {
                $shape[] = $label;
                $previous = $label;
            }

            $afterBlank = false;
        }

        return $shape;
    }

    /**
     * @param  list<string>  $expected
     * @param  list<string>  $actual
     */
    private function firstDivergence(array $expected, array $actual, string $source, string $locale): string
    {
        $length = max(count($expected), count($actual));

        for ($i = 0; $i < $length; $i++) {
            $a = $expected[$i] ?? null;
            $b = $actual[$i] ?? null;

            if ($a !== $b) {
                return sprintf(
                    'first difference at block %d: %s has %s, %s has %s',
                    $i + 1,
                    $source,
                    $a ?? 'no more blocks',
                    $locale,
                    $b ?? 'no more blocks',
                );
            }
        }

        return 'unknown difference';
    }

    /**
     * The lines of the chapter with every fenced code block line (fences included) replaced by
     * CODE_LINE, so line positions stay the same. An unclosed fence runs to the end, as in CommonMark.
     *
     * @return list<string>
     */
    private function linesOutsideFences(string $markdown): array
    {
        $lines = preg_split('/\R/', $markdown) ?: [];
        // Shared with ManualRenderer::summary(), so both agree on where a fence ends.
        $fenced = array_flip(ManualRenderer::fencedLines($lines));
        $result = [];

        foreach ($lines as $i => $line) {
            $result[] = isset($fenced[$i]) ? self::CODE_LINE : $line;
        }

        return $result;
    }

    /**
     * Removes inline code spans (a backtick run closed by a run of the same length within one
     * block), keeping line breaks.
     */
    private function withoutCodeSpans(string $text): string
    {
        $blocks = preg_split('/(\n[ \t]*\n)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

        foreach ($blocks as $i => $block) {
            $blocks[$i] = preg_replace_callback(
                '/(?<!`)(`+)(?!`)(.+?)(?<!`)\1(?!`)/s',
                static fn (array $m): string => self::CODE_LINE.str_repeat("\n", substr_count($m[0], "\n")),
                $block,
            ) ?? $block;
        }

        return implode('', $blocks);
    }

    /**
     * CommonMark raw HTML: open and closing tags (attributes may span lines), comments,
     * processing instructions, declarations and CDATA. `a < b`, `<https://…>` autolinks and
     * `\<tag>` are not HTML.
     */
    private function htmlPattern(): string
    {
        $attribute = '(?:\s+[A-Za-z_:][A-Za-z0-9_.:-]*(?:\s*=\s*(?:[^\s"\'=<>`]+|\'[^\']*\'|"[^"]*"))?)';

        return '/(?<!\\\\)(?:<[A-Za-z][A-Za-z0-9-]*'.$attribute.'*\s*\/?>|<\/[A-Za-z][A-Za-z0-9-]*\s*>|<!--|<\?|<![A-Za-z]|<!\[CDATA\[)/';
    }
}
