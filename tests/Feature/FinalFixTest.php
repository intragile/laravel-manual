<?php

declare(strict_types=1);

namespace IntrAgile\Manual\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use IntrAgile\Manual\Content\ContentTree;
use IntrAgile\Manual\Content\ManualRepository;
use IntrAgile\Manual\Rendering\ManualRenderer;
use IntrAgile\Manual\Search\ManualSearch;
use IntrAgile\Manual\Tests\TestCase;
use IntrAgile\Manual\Validation\ManualValidator;

final class FinalFixTest extends TestCase
{
    private string $tmp = '';

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            (new Filesystem)->deleteDirectory($this->tmp);
        }

        parent::tearDown();
    }

    /**
     * @param  array<string, string>  $chapters  slug => markdown
     * @param  list<string>  $callouts
     */
    private function content(array $chapters, array $callouts = ['tip', 'warning', 'note', 'steps']): void
    {
        $this->tmp = sys_get_temp_dir().'/manual-final-'.bin2hex(random_bytes(4));
        mkdir($this->tmp.'/hu/general', 0777, true);

        $names = array_map(static fn (string $s): string => substr($s, strlen('general/')), array_keys($chapters));
        file_put_contents($this->tmp.'/_meta.php', "<?php\nreturn ['general' => ['icon' => 'home', 'order' => 1, 'chapters' => ".var_export($names, true)."]];\n");
        file_put_contents($this->tmp.'/hu/_sections.php', "<?php\nreturn ['general' => 'Általános'];\n");

        foreach ($chapters as $slug => $markdown) {
            file_put_contents($this->tmp.'/hu/'.$slug.'.md', $markdown);
        }

        config([
            'manual.path' => $this->tmp,
            'manual.locales' => ['hu'],
            'manual.source_locale' => 'hu',
            'manual.fallback' => [],
            'manual.links' => null,
            'manual.known_routes' => null,
            'manual.callouts' => $callouts,
            'manual.cache.store' => 'array',
        ]);

        foreach ([ContentTree::class, ManualRenderer::class, ManualRepository::class, ManualSearch::class, ManualValidator::class] as $abstract) {
            app()->forgetInstance($abstract);
        }

        Cache::forgetDriver('array');
    }

    /**
     * @return list<string>
     */
    private function errors(): array
    {
        return app(ManualValidator::class)->errors();
    }

    public function test_callout_marker_with_a_space_renders_and_validates(): void
    {
        $this->content(['general/a' => "# A\n\nSummary text.\n\n::: tip\n\nHello\n\n:::\n"]);

        $html = app(ManualRepository::class)->chapter('general/a', 'hu')['html'] ?? '';

        $this->assertStringContainsString('<div class="manual-callout" data-type="tip">', $html);
        $this->assertSame([], $this->errors());
    }

    public function test_a_callout_type_with_a_space_is_ignored_by_renderer_and_validator(): void
    {
        $this->content(['general/a' => "# A\n\nSummary text.\n\n:::info box\n\nHello\n\n:::\n"], ['tip', 'info box']);

        $html = app(ManualRepository::class)->chapter('general/a', 'hu')['html'] ?? '';

        $this->assertStringNotContainsString('manual-callout', $html);
        $this->assertContains(
            '[hu] general/a: unknown callout type ":::info box" (allowed: tip)',
            $this->errors(),
        );
    }

    public function test_summary_skips_code_tables_and_images(): void
    {
        $r = new ManualRenderer([], []);

        $this->assertSame('Real prose.', $r->summary("# T\n\n```php\n\n\$a = 1;\n\n```\n\nReal prose.\n"));
        $this->assertSame('Real prose.', $r->summary("# T\n\n~~~\ncode\n\nmore code\n~~~\n\nReal prose.\n"));
        $this->assertSame('Real prose.', $r->summary("# T\n\n| a | b |\n|---|---|\n| 1 | 2 |\n\nReal prose.\n"));
        $this->assertSame('Real prose.', $r->summary("# T\n\n![Alt](img.png)\n\nReal prose.\n"));
        $this->assertSame('', $r->summary("# T\n\n```\ncode\n```\n"));
    }

    public function test_a_chapter_without_a_prose_paragraph_fails_the_check(): void
    {
        $this->content(['general/a' => "# A\n\n```\ncode\n```\n\n| a |\n|---|\n| 1 |\n"]);

        $this->assertContains('[hu] general/a: missing summary (the first paragraph after the title)', $this->errors());
    }

    public function test_chapter_numbers_are_unchanged(): void
    {
        $this->content([
            'general/a' => "# A\n\nOne.\n",
            'general/b' => "# B\n\nTwo.\n",
            'general/c' => "# C\n\nThree.\n",
        ]);

        $numbers = array_map(
            static fn (array $c): string => $c['number'],
            app(ManualRepository::class)->tree('hu')[0]['chapters'],
        );

        $this->assertSame(['1.1', '1.2', '1.3'], $numbers);
    }

    public function test_the_fingerprint_follows_symlinked_directories(): void
    {
        $this->content(['general/a' => "# A\n\nOne.\n"]);
        $target = $this->tmp.'-target';
        mkdir($target);
        file_put_contents($target.'/x.md', 'one');

        if (! @symlink($target, $this->tmp.'/linked')) {
            $this->markTestSkipped('Symlinks are not supported here.');
        }

        try {
            $first = app(ManualRepository::class)->fingerprint();
            app()->forgetInstance(ManualRepository::class);
            file_put_contents($target.'/x.md', 'one-and-more');
            clearstatcache();

            $this->assertNotSame($first, app(ManualRepository::class)->fingerprint());
        } finally {
            (new Filesystem)->deleteDirectory($target);
        }
    }

    public function test_the_fingerprint_is_memoized_per_instance(): void
    {
        $this->content(['general/a' => "# A\n\nOne.\n"]);
        $repo = app(ManualRepository::class);
        $first = $repo->fingerprint();

        file_put_contents($this->tmp.'/hu/general/a.md', "# A\n\nOne, but longer now.\n");
        clearstatcache();

        $this->assertSame($first, $repo->fingerprint());
    }
}
