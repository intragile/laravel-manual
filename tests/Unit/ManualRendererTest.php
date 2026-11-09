<?php

declare(strict_types=1);

namespace IntrAgile\Manual\Tests\Unit;

use IntrAgile\Manual\Rendering\ManualRenderer;
use PHPUnit\Framework\TestCase;

final class ManualRendererTest extends TestCase
{
    /**
     * @param  array<string, array<string, string>>  $links
     */
    private function renderer(array $links = []): ManualRenderer
    {
        return new ManualRenderer(['tip', 'warning', 'note', 'steps'], $links + [
            'docs' => ['en' => 'https://example.com/en', 'hu' => 'https://example.com/hu'],
        ]);
    }

    public function test_tokens_render(): void
    {
        $r = $this->renderer();
        $md = '[[manual:a/b|F]] [[route:users-list|R]] [[link:docs|D]] [[icon:plus]]';

        $html = $r->toHtml($md, 'hu');

        $this->assertStringContainsString('<a class="manual-link" data-slug="a/b" tabindex="0" role="link">F</a>', $html);
        $this->assertStringContainsString('<a class="manual-route" data-route="users-list" tabindex="0" role="link">R</a>', $html);
        $this->assertStringContainsString('<a class="manual-external" href="https://example.com/hu" target="_blank" rel="noopener noreferrer">D</a>', $html);
        $this->assertStringContainsString('<i class="manual-icon" data-icon="plus" aria-hidden="true"></i>', $html);
        $this->assertStringContainsString('href="https://example.com/en"', $r->toHtml('[[link:docs|D]]', 'de'));
    }

    public function test_unknown_link_key_keeps_label(): void
    {
        $html = $this->renderer()->toHtml('[[link:nope|Label]]', 'en');

        $this->assertStringContainsString('<p>Label</p>', $html);
        $this->assertStringNotContainsString('<a', $html);
    }

    public function test_label_is_escaped_exactly_once(): void
    {
        $html = $this->renderer()->toHtml('[[manual:a/b|Tom & <b>Jerry</b> "x"]] [[link:docs|A & B]]', 'en');

        $this->assertStringContainsString('>Tom &amp; Jerry &quot;x&quot;</a>', $html);
        $this->assertStringContainsString('>A &amp; B</a>', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
        $this->assertStringNotContainsString('<b>', $html);
    }

    public function test_label_with_angle_brackets_is_escaped(): void
    {
        $html = $this->renderer()->toHtml('[[route:r|a &lt; b \< c]]', 'en');

        $this->assertStringNotContainsString('< c', $html);
        $this->assertStringNotContainsString('&amp;lt;', $html);
    }

    public function test_unsafe_token_values_stay_plain_escaped_text(): void
    {
        $html = $this->renderer()->toHtml('[[manual:a"onmouseover=x|L]] [[route:a b|L]] [[icon:x"y]]', 'en');

        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringNotContainsString('<i ', $html);
        $this->assertStringContainsString('[[manual:a&quot;onmouseover=x|L]]', $html);
    }

    public function test_unsafe_url_in_links_map_yields_label_only(): void
    {
        $r = $this->renderer([
            'bad' => ['en' => 'javascript:alert(1)'],
            'bad2' => ['en' => 'data:text/html,x'],
            'bad3' => ['en' => 'https://x.com/"onclick="y'],
            'mail' => ['en' => 'mailto:info@example.com'],
        ]);

        $html = $r->toHtml('[[link:bad|A]] [[link:bad2|B]] [[link:bad3|C]] [[link:mail|M]]', 'en');

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('data:', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringContainsString('A B C', $html);
        $this->assertStringContainsString('href="mailto:info@example.com"', $html);
    }

    public function test_tokens_in_code_are_not_converted(): void
    {
        $md = "Inline `[[icon:plus]]` here.\n\n```\n[[manual:a/b|F]]\n[[link:docs|D]]\n```\n\n[x [[icon:plus]]](https://example.com/[[icon:y]])";

        $html = $this->renderer()->toHtml($md, 'en');

        $this->assertStringContainsString('<code>[[icon:plus]]</code>', $html);
        $this->assertStringContainsString('[[manual:a/b|F]]', $html);
        $this->assertStringContainsString('[[link:docs|D]]', $html);
        $this->assertStringNotContainsString('manual-icon', $html);
        $this->assertStringNotContainsString('data-slug', $html);
    }

    public function test_callout_wraps_content_and_unknown_type_is_left_alone(): void
    {
        $html = $this->renderer()->toHtml("# T\n\n:::tip\n\nHello\n\n:::\n\n:::foo\n\nX\n", 'en');

        $this->assertStringContainsString('<div class="manual-callout" data-type="tip">', $html);
        $this->assertStringContainsString('</div>', $html);
        $this->assertStringNotContainsString('data-type="foo"', $html);
        $this->assertStringContainsString('<p>:::foo</p>', $html);
        $this->assertStringNotContainsString('<i ', $html);
    }

    public function test_unclosed_callout_is_closed_and_stray_closer_left_alone(): void
    {
        $r = $this->renderer();

        $unclosed = $r->toHtml(":::tip\n\nHello\n", 'en');
        $this->assertSame(substr_count($unclosed, '<div'), substr_count($unclosed, '</div>'));
        $this->assertStringEndsWith('</div>', $unclosed);

        $stray = $r->toHtml("Before\n\n:::\n\nAfter\n", 'en');
        $this->assertStringNotContainsString('</div>', $stray);
        $this->assertStringContainsString('<p>:::</p>', $stray);

        $mixed = $r->toHtml(":::\n\n:::note\n\nA\n\n:::\n\n:::\n", 'en');
        $this->assertSame(1, substr_count($mixed, '<div'));
        $this->assertSame(1, substr_count($mixed, '</div>'));
    }

    public function test_heading_anchors_strip_accents(): void
    {
        $html = $this->renderer()->toHtml("## Első lépések\n\n### Értesítési csatornák\n", 'hu');

        $this->assertStringContainsString('<h2 id="elso-lepesek">', $html);
        $this->assertStringContainsString('<h3 id="ertesitesi-csatornak">', $html);
        $this->assertSame('elso-lepesek', ManualRenderer::anchor('Első lépések'));
    }

    public function test_duplicate_headings_get_unique_anchors_matching_headings(): void
    {
        $r = $this->renderer();
        $md = "## Setup\n\n## Setup\n\n### Setup\n\n## Tom & Jerry\n\n```\n## Setup\n```\n";

        $html = $r->toHtml($md, 'en');
        $headings = $r->headings($md);

        $this->assertSame(['setup', 'setup-2', 'setup-3', 'tom-jerry'], array_column($headings, 'anchor'));
        $this->assertSame('Tom & Jerry', $headings[3]['text']);
        $this->assertSame([2, 2, 3, 2], array_column($headings, 'level'));
        foreach ($headings as $h) {
            $this->assertStringContainsString('id="'.$h['anchor'].'"', $html);
        }
    }

    public function test_tables_are_wrapped(): void
    {
        $html = $this->renderer()->toHtml("| A | B |\n|---|---|\n| 1 | 2 |\n", 'en');

        $this->assertStringContainsString('<div class="manual-table-wrap"><table>', $html);
        $this->assertStringContainsString('</table></div>', $html);
    }

    public function test_raw_html_and_unsafe_links_are_stripped(): void
    {
        $html = $this->renderer()->toHtml("<script>alert(1)</script>\n\n[x](javascript:alert(1))\n\n<b onclick=\"x\">y</b>", 'en');

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('<b', $html);
    }

    public function test_title_summary_headings_plaintext(): void
    {
        $r = $this->renderer();
        $md = "# Title here\n\nThe **first** paragraph with [[manual:a/b|a link]].\n\n## Part one\n\n:::tip\n\nBody *text* [[icon:plus]]\n\n:::\n";

        $this->assertSame('Title here', $r->title($md));
        $this->assertSame('The first paragraph with a link.', $r->summary($md));
        $this->assertStringNotContainsString('**', $r->summary($md));
        $this->assertSame([['level' => 2, 'text' => 'Part one', 'anchor' => 'part-one']], $r->headings($md));
        $this->assertStringNotContainsString('[[', $r->plainText($md));
        $this->assertStringContainsString('Body text', $r->plainText($md));
    }

    private function assertBalanced(string $html): void
    {
        preg_match_all('/<(\/?)(div|blockquote|ul|ol|li)\b[^>]*>/', $html, $tags, PREG_SET_ORDER);
        $stack = [];
        foreach ($tags as $t) {
            if ($t[1] === '') {
                $stack[] = $t[2];
            } else {
                $this->assertSame(array_pop($stack), $t[2], 'bad nesting in '.$html);
            }
        }
        $this->assertSame([], $stack, $html);
        $this->assertSame(substr_count($html, '<div'), substr_count($html, '</div>'));
    }

    public function test_callouts_stay_balanced_inside_blockquotes_and_lists(): void
    {
        $r = $this->renderer();

        $a = $r->toHtml("> :::tip\n>\n> inside\n\noutside\n\n:::", 'en');
        $this->assertBalanced($a);
        $this->assertStringNotContainsString('manual-callout', $a);

        $b = $r->toHtml(":::tip\n\n> x\n>\n> :::", 'en');
        $this->assertBalanced($b);
        $this->assertStringContainsString('manual-callout', $b);

        $c = $r->toHtml("- a\n\n- :::tip\n\n- b", 'en');
        $this->assertBalanced($c);
        $this->assertStringNotContainsString('manual-callout', $c);
    }

    public function test_title_is_plain_text(): void
    {
        $this->assertSame('alert(1) B', $this->renderer()->title("# <script>alert(1)</script> **B**\n"));
        $this->assertSame('api_key and link', $this->renderer()->title('# api_key and [[manual:a/b|link]]'));
    }

    public function test_plain_text_has_no_tag_fragments(): void
    {
        $text = $this->renderer()->plainText('<script>alert(1)</script> hi');

        $this->assertStringNotContainsString('<', $text);
        $this->assertStringContainsString('hi', $text);
    }
}
