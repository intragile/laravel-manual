<?php

declare(strict_types=1);

namespace IntrAgile\Manual\Rendering;

use IntrAgile\Manual\Support\Ascii;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Renders manual chapters (Markdown) to safe HTML.
 *
 * CommonMark runs with `html_input: strip` and `allow_unsafe_links: false`, so raw HTML in the
 * source never reaches the output. The manual's own markup (`[[manual:]]`, `[[route:]]`,
 * `[[link:]]`, `[[icon:]]` tokens and `:::type` containers) is resolved on the finished HTML,
 * because the strip step would otherwise drop anything inserted before it.
 *
 * Token resolution only touches text nodes: never code spans, code blocks, link text or tag
 * attributes. Text nodes are already HTML-escaped by CommonMark, so labels are used as they
 * are (escaping them again would double-encode); values that become attributes are restricted
 * to a safe charset or escaped.
 *
 * @internal
 */
final class ManualRenderer
{
    /** Charset of token values (slugs, route names, link keys, icon names); the validator reuses it. */
    public const SAFE_VALUE = '[A-Za-z0-9._\/-]+';

    /** URLs `[[link:]]` may point to; anything else renders as plain label text. */
    public const SAFE_URL = '/^(?:https?:\/\/[^\/\s"<>]+|mailto:[^\s"<>]+)[^\s"<>\x00-\x1f]*$/i';

    /**
     * @param  list<string>  $callouts  allowed `:::type` container names
     * @param  array<string, array<string, string>>  $links  `[[link:key]]` key => locale => URL
     */
    public function __construct(
        private readonly array $callouts,
        private readonly array $links,
    ) {}

    public function toHtml(string $markdown, string $locale): string
    {
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'renderer' => ['soft_break' => "\n"],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new TableExtension);

        $html = (new MarkdownConverter($environment))->convert($markdown)->getContent();

        $html = $this->resolveTokens($html, $locale);
        $html = $this->resolveContainers($html);
        $html = $this->addHeadingAnchors($html);

        return str_replace(
            ['<table>', '</table>'],
            ['<div class="manual-table-wrap"><table>', '</table></div>'],
            $html,
        );
    }

    /**
     * The chapter title: the first level-1 heading.
     */
    public function title(string $markdown): string
    {
        if (preg_match('/^\#\s+(.+)$/m', $markdown, $m) !== 1) {
            return '';
        }

        $text = $this->stripInline($m[1]);
        $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text) ?? $text;
        $text = str_replace(['**', '__', '*', '`'], '', strip_tags($text));

        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }

    /**
     * The chapter summary: the first paragraph after the title, as plain text.
     */
    public function summary(string $markdown): string
    {
        $body = preg_replace('/^\#\s+.+$/m', '', $markdown, 1) ?? $markdown;
        // Fenced code blocks (an unclosed fence runs to the end) never make a summary.
        $lines = preg_split('/\R/', $body) ?: [];
        foreach (self::fencedLines($lines) as $i) {
            $lines[$i] = '';
        }
        $body = implode("\n", $lines);

        foreach (preg_split('/\R{2,}/', trim($body)) ?: [] as $block) {
            $block = trim($block);

            if ($block !== '' && preg_match('/^(#|[-*+](?:[ \t]|$)|(?:[-*_][ \t]*){3,}$|[>|]|!\[|\d+[.)]|:::)/', $block) !== 1) {
                return trim(preg_replace('/\s+/', ' ', strip_tags($this->stripMarkdown($block))) ?? '');
            }
        }

        return '';
    }

    /**
     * The level 2 and 3 headings with the very same anchors toHtml() puts in `id=`.
     *
     * @return list<array{level: int, text: string, anchor: string}>
     */
    public function headings(string $markdown): array
    {
        $html = $this->toHtml($markdown, '');
        preg_match_all('/<h([23]) id="([^"]*)">(.*?)<\/h\1>/s', $html, $matches, PREG_SET_ORDER);

        $headings = [];
        foreach ($matches as $m) {
            $headings[] = [
                'level' => (int) $m[1],
                'text' => trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                'anchor' => $m[2],
            ];
        }

        return $headings;
    }

    /**
     * Tag-free, markup-free text for search and excerpts.
     *
     * The title (the first `# ` heading, as title() reads it) is left out: it is served on its own.
     * A heading without terminal punctuation (`.`, `!`, `?`, `:`) gets a `.`, so it does not run
     * into the following sentence.
     */
    public function plainText(string $markdown): string
    {
        $text = preg_replace('/^\#\s+.+$/m', '', $markdown, 1) ?? $markdown;

        // Headings only outside fenced code: a `# comment` line in a code block is not a heading.
        $lines = preg_split('/\R/', $text) ?: [];
        $fenced = array_flip(self::fencedLines($lines));

        foreach ($lines as $i => $line) {
            if (! isset($fenced[$i]) && preg_match('/^[ ]{0,3}#{1,6}(?:[ \t]+(.*?))?(?:[ \t]+#+)?[ \t]*$/', $line, $m) === 1) {
                $heading = trim($m[1] ?? '');
                // Emphasis markers are stripped later, so `**Done.**` already ends with punctuation.
                $lines[$i] = $heading === '' || preg_match('/[.!?:][*_`]*$/', $heading) === 1 ? $heading : $heading.'.';
            }
        }

        $text = strip_tags($this->stripMarkdown(implode("\n", $lines)));
        $text = preg_replace('/^:::.*$/m', '', $text) ?? $text;
        $text = preg_replace('/[#>*_`|-]+/', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }

    /**
     * Indexes of the lines that belong to a fenced code block, fence lines included, the way
     * CommonMark reads them: an opener of 3+ backticks or tildes indented at most 3 spaces (a
     * backtick opener's info string must not contain a backtick), closed only by a run of the
     * same character at least as long, indented at most 3 spaces and followed by nothing but
     * spaces or tabs. An unclosed fence runs to the end.
     *
     * @param  list<string>  $lines
     * @return list<int>
     *
     * @internal
     */
    public static function fencedLines(array $lines): array
    {
        $fenced = [];
        $close = null;

        foreach ($lines as $i => $line) {
            if ($close === null) {
                if (preg_match('/^ {0,3}(`{3,}|~{3,})(.*)$/', $line, $m) === 1
                    && ! ($m[1][0] === '`' && str_contains($m[2], '`'))) {
                    $close = '/^ {0,3}'.preg_quote($m[1][0], '/').'{'.strlen($m[1]).',}[ \t]*$/';
                    $fenced[] = $i;
                }

                continue;
            }

            $fenced[] = $i;

            if (preg_match($close, $line) === 1) {
                $close = null;
            }
        }

        return $fenced;
    }

    /**
     * Heading text to anchor: lowercase ASCII, accents folded, `-` separated.
     */
    public static function anchor(string $text): string
    {
        $slug = strtolower(Ascii::fold($text));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';

        return trim($slug, '-');
    }

    /**
     * Resolves the inline tokens in text nodes only.
     */
    private function resolveTokens(string $html, string $locale): string
    {
        $parts = preg_split('/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $skip = 0;

        foreach ($parts as $i => $part) {
            if ($part[0] === '<') {
                if (preg_match('/^<(code|pre|a)[\s>]/i', $part) === 1) {
                    $skip++;
                } elseif (preg_match('/^<\/(code|pre|a)>/i', $part) === 1 && $skip > 0) {
                    $skip--;
                }

                continue;
            }

            if ($skip === 0 && str_contains($part, '[[')) {
                $parts[$i] = $this->resolveTokensInText($part, $locale);
            }
        }

        return implode('', $parts);
    }

    private function resolveTokensInText(string $text, string $locale): string
    {
        $safe = self::SAFE_VALUE;

        $text = preg_replace_callback(
            '/\[\[icon:('.$safe.')\]\]/',
            static fn (array $m): string => '<i class="manual-icon" data-icon="'.self::attr($m[1]).'" aria-hidden="true"></i>',
            $text,
        ) ?? $text;

        return preg_replace_callback(
            '/\[\[(manual|route|link):('.$safe.')(?:\|([^\]]+))?\]\]/',
            function (array $m) use ($locale): string {
                // The label comes from CommonMark's output: already escaped, used as is.
                $label = ($m[3] ?? '') !== '' ? $m[3] : self::attr($m[2]);

                return match ($m[1]) {
                    'manual' => '<a class="manual-link" data-slug="'.self::attr($m[2]).'" tabindex="0" role="link">'.$label.'</a>',
                    'route' => '<a class="manual-route" data-route="'.self::attr($m[2]).'" tabindex="0" role="link">'.$label.'</a>',
                    default => $this->externalLink($m[2], $label, $locale),
                };
            },
            $text,
        ) ?? $text;
    }

    private function externalLink(string $key, string $label, string $locale): string
    {
        $byLocale = $this->links[$key] ?? null;

        if ($byLocale === null || $byLocale === []) {
            return $label;
        }

        $url = $byLocale[$locale] ?? $byLocale['en'] ?? reset($byLocale);

        if (preg_match(self::SAFE_URL, $url) !== 1) {
            return $label;
        }

        return '<a class="manual-external" href="'.self::attr($url).'" target="_blank" rel="noopener noreferrer">'.$label.'</a>';
    }

    /**
     * The callout names that are usable: only `[A-Za-z0-9_-]+` names count, for the renderer
     * and for `manual:check` alike.
     *
     * @param  list<string>  $callouts
     * @return list<string>
     *
     * @internal
     */
    public static function validCallouts(array $callouts): array
    {
        return array_values(array_filter(
            $callouts,
            static fn (string $type): bool => preg_match('/^[A-Za-z0-9_-]+$/', $type) === 1,
        ));
    }

    /**
     * `:::type` ... `:::` containers. Opening and closing lines must be paragraphs of their own
     * (blank lines around them). Always balanced: unclosed containers are closed at the end, a
     * stray `:::` is left alone.
     */
    private function resolveContainers(string $html): string
    {
        $types = self::validCallouts($this->callouts);

        if ($types === []) {
            return $html;
        }

        $typePattern = '/^:::[ \t]*(?:('.implode('|', array_map(static fn (string $t): string => preg_quote($t, '/'), $types)).'))?$/';
        $parts = preg_split('/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $open = 0;
        $depth = 0;
        $out = [];

        for ($i = 0, $n = count($parts); $i < $n; $i++) {
            $part = $parts[$i];

            if ($part[0] === '<') {
                if (preg_match('/^<(blockquote|li|td|th)[\s>]/i', $part) === 1) {
                    $depth++;
                } elseif (preg_match('/^<\/(blockquote|li|td|th)>/i', $part) === 1 && $depth > 0) {
                    $depth--;
                }
            }

            if (
                $depth === 0
                && $part === '<p>'
                && ($parts[$i + 2] ?? '') === '</p>'
                && preg_match($typePattern, $parts[$i + 1], $m) === 1
            ) {
                if (($m[1] ?? '') !== '') {
                    $open++;
                    $out[] = '<div class="manual-callout" data-type="'.self::attr($m[1]).'">';
                    $i += 2;

                    continue;
                }

                if ($open > 0) {
                    $open--;
                    $out[] = '</div>';
                    $i += 2;

                    continue;
                }
            }

            $out[] = $part;
        }

        $html = implode('', $out);

        return $html.str_repeat('</div>', $open);
    }

    private function addHeadingAnchors(string $html): string
    {
        $seen = [];

        return preg_replace_callback(
            '/<h([23])>(.*?)<\/h\1>/s',
            static function (array $m) use (&$seen): string {
                $base = self::anchor(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $base = $base === '' ? 'section' : $base;
                $anchor = $base;

                for ($n = 2; isset($seen[$anchor]); $n++) {
                    $anchor = $base.'-'.$n;
                }
                $seen[$anchor] = true;

                return '<h'.$m[1].' id="'.$anchor.'">'.$m[2].'</h'.$m[1].'>';
            },
            $html,
        ) ?? $html;
    }

    private function stripInline(string $text): string
    {
        $text = preg_replace('/\[\[icon:'.self::SAFE_VALUE.'\]\]/', '', $text) ?? $text;

        return preg_replace_callback(
            '/\[\[(?:manual|route|link):('.self::SAFE_VALUE.')(?:\|([^\]]+))?\]\]/',
            static fn (array $m): string => ($m[2] ?? '') !== '' ? $m[2] : $m[1],
            $text,
        ) ?? $text;
    }

    private function stripMarkdown(string $text): string
    {
        $text = $this->stripInline($text);
        $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text) ?? $text;

        return preg_replace('/(\*\*|\*|__|_|`)/', '', $text) ?? $text;
    }

    private static function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
