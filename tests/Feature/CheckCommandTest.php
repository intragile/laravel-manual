<?php

declare(strict_types=1);

namespace IntrAgile\Manual\Tests\Feature;

use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use IntrAgile\Manual\Content\ContentTree;
use IntrAgile\Manual\Content\ManualRepository;
use IntrAgile\Manual\Rendering\ManualRenderer;
use IntrAgile\Manual\Search\ManualSearch;
use IntrAgile\Manual\Tests\TestCase;
use IntrAgile\Manual\Validation\ManualValidator;

final class CheckCommandTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../Fixtures/check/';

    /**
     * @param  list<string>  $locales
     */
    private function useFixture(string $name, array $locales = ['hu', 'en'], mixed $knownRoutes = null): void
    {
        config([
            'manual.path' => self::FIXTURES.$name,
            'manual.locales' => $locales,
            'manual.source_locale' => 'hu',
            'manual.fallback' => ['en'],
            'manual.links' => null,
            'manual.known_routes' => $knownRoutes,
        ]);
    }

    /**
     * @param  list<string>|null  $only
     * @return list<string>
     */
    private function errors(?array $only = null): array
    {
        return app(ManualValidator::class)->errors($only);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{0: int, 1: string}
     */
    private function check(array $parameters = []): array
    {
        $exit = Artisan::call('manual:check', $parameters);

        return [$exit, Artisan::output()];
    }

    /**
     * Errors of one chapter (or other location prefix), without the prefix.
     *
     * @return list<string>
     */
    private function errorsFor(string $prefix): array
    {
        $found = [];

        foreach ($this->errors() as $error) {
            if (str_starts_with($error, $prefix.': ')) {
                $found[] = substr($error, strlen($prefix) + 2);
            }
        }

        return $found;
    }

    public function test_valid_fixture_passes(): void
    {
        $this->useFixture('valid', knownRoutes: ['dashboard']);

        $this->assertSame([], $this->errors());

        [$exit, $output] = $this->check();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Manual OK', $output);
    }

    public function test_valid_fixture_passes_with_a_known_routes_callable(): void
    {
        $this->useFixture('valid', knownRoutes: static fn (): array => ['dashboard', 'settings']);

        $this->assertSame([], $this->errors());
    }

    public function test_the_fixture_of_earlier_tests_is_reported_as_incomplete(): void
    {
        // tests/Fixtures/manual is deliberately missing English content (fallback tests).
        config([
            'manual.path' => __DIR__.'/../Fixtures/manual',
            'manual.locales' => ['hu', 'en'],
            'manual.source_locale' => 'hu',
        ]);

        $this->assertSame([
            '[en] advanced/tuning: missing translation',
            '[en] advanced: missing section title in _sections.php',
        ], $this->errors());
    }

    public function test_structure_errors_are_complete_and_sorted(): void
    {
        $this->useFixture('structure');

        $this->assertSame([
            '[en] general/ghost: missing translation',
            '[en] general/intro: block structure differs from hu (2 blocks instead of 4); first difference at block 3: hu has H2 heading, en has no more blocks',
            '[en] general/setup: missing translation',
            '[hu] _sections.php: unknown section "stale" (not in _meta.php)',
            '[hu] extra/only-en: missing in source locale',
            '[hu] extra: missing section title in _sections.php',
            '[hu] general/ghost: missing in source locale',
            '[hu] general/orphan: file is not listed in _meta.php',
            '_meta.php: section "empty-section": has no chapters',
        ], $this->errors());
    }

    public function test_missing_translation_is_reported(): void
    {
        $this->useFixture('structure');

        $this->assertContains('[en] general/setup: missing translation', $this->errors());
    }

    public function test_chapter_missing_in_source_locale_is_reported_even_when_translated(): void
    {
        $this->useFixture('structure');

        $errors = $this->errors();
        $this->assertContains('[hu] extra/only-en: missing in source locale', $errors);
        $this->assertContains('[hu] general/ghost: missing in source locale', $errors);
        $this->assertContains('[en] general/ghost: missing translation', $errors);
    }

    public function test_orphan_file_is_reported(): void
    {
        $this->useFixture('structure');

        $this->assertContains('[hu] general/orphan: file is not listed in _meta.php', $this->errors());
    }

    public function test_section_without_chapters_is_reported(): void
    {
        $this->useFixture('structure');

        $this->assertContains('_meta.php: section "empty-section": has no chapters', $this->errors());
    }

    public function test_missing_section_title_is_reported(): void
    {
        $this->useFixture('structure');

        $errors = $this->errors();
        $this->assertContains('[hu] extra: missing section title in _sections.php', $errors);
        $this->assertContains('[hu] _sections.php: unknown section "stale" (not in _meta.php)', $errors);
        $this->assertNotContains('[en] extra: missing section title in _sections.php', $errors);
    }

    public function test_parity_detects_missing_section_in_translation(): void
    {
        $this->useFixture('structure');

        $this->assertContains(
            '[en] general/intro: block structure differs from hu (2 blocks instead of 4); first difference at block 3: hu has H2 heading, en has no more blocks',
            $this->errors(),
        );
    }

    public function test_parity_ignores_line_wrapping(): void
    {
        // valid: hu wraps a paragraph, a list item and a quote over two lines, en does not.
        $this->useFixture('valid');

        $this->assertSame([], $this->errors());
    }

    public function test_raw_html_is_reported(): void
    {
        $this->useFixture('content', ['hu']);

        $this->assertSame(['raw HTML is not allowed: <div class="note">'], $this->errorsFor('[hu] general/block-html'));
        $this->assertSame(['raw HTML is not allowed: <kbd>'], $this->errorsFor('[hu] general/inline-html'));
    }

    public function test_code_comparisons_escapes_and_autolinks_are_not_reported(): void
    {
        // prose.md: `a < b`, `c > d`, `\<div>`, an autolink, HTML / tokens / headings / `:::`
        // inside code spans, a backtick fence and a tilde fence.
        $this->useFixture('content', ['hu']);

        $this->assertSame([], $this->errorsFor('[hu] general/prose'));
    }

    public function test_title_summary_and_heading_rules(): void
    {
        $this->useFixture('content', ['hu']);

        $this->assertSame([
            'H4 or deeper heading (only H2 and H3 are allowed)',
            'more than one H1 heading (a chapter has exactly one title)',
        ], $this->errorsFor('[hu] general/headings'));
        $this->assertSame(['missing title (the first "# " heading)'], $this->errorsFor('[hu] general/no-title'));
        $this->assertSame(['missing summary (the first paragraph after the title)'], $this->errorsFor('[hu] general/no-summary'));
    }

    public function test_callout_rules(): void
    {
        $this->useFixture('content', ['hu']);

        $this->assertSame([
            '":::" markers need a blank line before and after',
            'unbalanced ":::" markers (every ":::type" needs its own closing ":::")',
            'unknown callout type ":::danger" (allowed: tip, warning, note, steps)',
        ], $this->errorsFor('[hu] general/callouts'));
    }

    public function test_broken_internal_link_is_reported(): void
    {
        $this->useFixture('content', ['hu']);

        $this->assertContains('broken manual link "general/nope" (not in _meta.php)', $this->errorsFor('[hu] general/tokens'));
    }

    public function test_unknown_link_key_is_reported(): void
    {
        $this->useFixture('content', ['hu']);

        $errors = $this->errorsFor('[hu] general/tokens');
        $this->assertContains('unknown link key "nokey" (add it to the links map)', $errors);
        $this->assertNotContains('unknown link key "docs" (add it to the links map)', $errors);
    }

    public function test_unknown_route_is_reported_only_when_known_routes_given(): void
    {
        $message = 'unknown route "admin.secret" (not in manual.known_routes)';

        $this->useFixture('content', ['hu']);
        $this->assertNotContains($message, $this->errorsFor('[hu] general/tokens'));

        $this->useFixture('content', ['hu'], ['dashboard']);
        $errors = $this->errorsFor('[hu] general/tokens');
        $this->assertContains($message, $errors);
        $this->assertNotContains('unknown route "dashboard" (not in manual.known_routes)', $errors);

        $this->useFixture('content', ['hu'], static fn (): array => ['dashboard']);
        $this->assertContains($message, $this->errorsFor('[hu] general/tokens'));
    }

    public function test_invalid_icons_unknown_token_kinds_and_malformed_tokens_are_reported(): void
    {
        $this->useFixture('content', ['hu']);

        $errors = $this->errorsFor('[hu] general/tokens');
        $this->assertContains('invalid icon name in [[icon:bad name]] (allowed: letters, digits, ".", "_", "/" and "-"; no label)', $errors);
        $this->assertContains('unknown token kind "foo" in [[foo:bar]]', $errors);
        $this->assertContains('malformed token [[nothing]]', $errors);
        $this->assertContains('malformed token [[manual:general/prose|]] (expected [[manual:name|label]], name: letters, digits, ".", "_", "/" and "-")', $errors);
        $this->assertCount(6, $errors, 'plus the broken link and the unknown link key; no route check without known_routes');
    }

    public function test_unescaped_pipe_in_a_table_cell_is_reported(): void
    {
        $this->useFixture('content', ['hu']);

        $this->assertSame(
            ['unresolved "[[" in the rendered page (inside a table cell write "\|" instead of "|" in a token)'],
            $this->errorsFor('[hu] general/table'),
        );
    }

    public function test_invalid_link_url_is_reported(): void
    {
        $this->useFixture('valid');
        config(['manual.links' => ['docs' => ['hu' => 'javascript:alert(1)', 'en' => 'https://example.com']]]);

        $this->assertSame(['links: "docs" (hu): invalid URL (only http, https and mailto URLs are linked)'], $this->errors());
    }

    public function test_failing_known_routes_callable_is_reported(): void
    {
        $this->useFixture('valid', knownRoutes: static fn (): never => throw new \RuntimeException('no router'));

        $this->assertSame(['manual.known_routes: the callable failed (RuntimeException: no router)'], $this->errors());

        $this->useFixture('valid', knownRoutes: 'not callable');
        $this->assertSame(['manual.known_routes: must be a list of route names or a callable returning one'], $this->errors());
    }

    public function test_malformed_meta_is_reported_not_thrown(): void
    {
        $this->useFixture('malformed', ['hu']);
        $this->assertSame(['_meta.php: must return an array, returns string'], $this->errors());

        $this->useFixture('broken-meta', ['hu']);
        $this->assertSame(['_meta.php: cannot be loaded (RuntimeException: boom)'], $this->errors());

        $this->useFixture('empty', ['hu']);
        $this->assertSame(['_meta.php: the manual is empty (no chapters listed)'], $this->errors());

        $this->useFixture('does-not-exist', ['hu']);
        $this->assertSame(['_meta.php: file not found'], $this->errors());

        $this->useFixture('malformed-entries', ['hu']);
        $this->assertSame([
            '[hu] _sections.php: must return an array, returns int',
            '_meta.php: section "Bad Id": invalid section id (use letters, digits, "-" and "_")',
            '_meta.php: section "general": chapter "intro" is listed more than once',
            '_meta.php: section "general": invalid chapter slug "../etc" (use letters, digits, "-" and "_")',
            '_meta.php: section "general": invalid chapter slug int (use letters, digits, "-" and "_")',
            '_meta.php: section "not-a-list": "chapters" must be a list of chapter slugs',
            '_meta.php: section "not-a-list": "icon" must be a string',
            '_meta.php: section "not-a-list": "order" must be an integer',
            '_meta.php: section "scalar": must be an array',
        ], $this->errors());

        [$exit] = $this->check();
        $this->assertSame(1, $exit);
    }

    public function test_command_lists_the_errors_and_exits_with_1(): void
    {
        $this->useFixture('structure');

        [$exit, $output] = $this->check();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('[hu] general/orphan: file is not listed in _meta.php', $output);
        $this->assertStringContainsString('Manual check failed with 9 errors.', $output);
        $this->assertStringNotContainsString('Manual OK', $output);
    }

    public function test_locale_option_checks_the_source_and_the_requested_locale_only(): void
    {
        $this->useFixture('structure', ['hu', 'en', 'de']);

        $this->assertContains('[de] general/intro: missing translation', $this->errors());

        [$exit, $output] = $this->check(['--locale' => ['en']]);

        $this->assertSame(1, $exit);
        $this->assertStringNotContainsString('[de]', $output);
        $this->assertStringContainsString('[en] general/setup: missing translation', $output);
        $this->assertStringContainsString('[hu] general/orphan: file is not listed in _meta.php', $output);

        $this->assertSame([], array_filter($this->errors(['hu']), static fn (string $e): bool => str_starts_with($e, '[en]')));
    }

    public function test_unconfigured_locale_option_fails(): void
    {
        $this->useFixture('valid');

        [$exit, $output] = $this->check(['--locale' => ['xx']]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Locale "xx" is not configured (the source locale is "hu"; others must be listed in manual.locales).', $output);
        $this->assertStringContainsString('Manual check failed with 1 error.', $output);

        [$exit, $output] = $this->check(['--locale' => ['en']]);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Manual OK', $output);
    }

    public function test_fix1_fixture_reports_exactly_these_errors(): void
    {
        $this->useFixture('fix1');

        $this->assertSame([
            '[en] general/list: block structure differs from hu (2 blocks instead of 4); first difference at block 3: hu has numbered item, en has no more blocks',
            '[en] general/nested: callout markers must start at the beginning of the line (not inside a list or quote)',
            '[en] general/setext: setext H1 heading ("===" underline) is not supported (write "# Title")',
            '[hu] general/nested: callout markers must start at the beginning of the line (not inside a list or quote)',
            '[hu] general/setext: setext H1 heading ("===" underline) is not supported (write "# Title")',
        ], $this->errors());
    }

    public function test_a_wrapped_number_is_paragraph_text_in_the_parity_check(): void
    {
        // hu: "...amely\n2026. november 10-én jelent meg." against en on one line.
        $this->useFixture('fix1');

        $this->assertSame([], $this->errorsFor('[en] general/dates'));
    }

    public function test_a_list_starting_at_1_interrupts_a_paragraph_in_the_parity_check(): void
    {
        $this->useFixture('fix1');

        $this->assertSame(
            ['block structure differs from hu (2 blocks instead of 4); first difference at block 3: hu has numbered item, en has no more blocks'],
            $this->errorsFor('[en] general/list'),
        );
    }

    public function test_callout_markers_inside_a_list_or_quote_are_reported(): void
    {
        $this->useFixture('fix1');

        $this->assertSame(
            ['callout markers must start at the beginning of the line (not inside a list or quote)'],
            $this->errorsFor('[hu] general/nested'),
        );
    }

    public function test_setext_headings(): void
    {
        $this->useFixture('fix1');

        // A setext H1 is named as such, not as a missing title; a setext H2 is fine.
        $this->assertSame(
            ['setext H1 heading ("===" underline) is not supported (write "# Title")'],
            $this->errorsFor('[hu] general/setext'),
        );
        $this->assertSame([], $this->errorsFor('[hu] general/setext-h2'));
        $this->assertSame([], $this->errorsFor('[en] general/setext-h2'));
    }

    private function useCachedFixture(string $store): void
    {
        $this->useFixture('valid');
        config(['manual.cache.store' => $store]);

        foreach ([ContentTree::class, ManualRenderer::class, ManualRepository::class, ManualSearch::class] as $abstract) {
            app()->forgetInstance($abstract);
        }
    }

    /**
     * @return list<string> keys of the cache misses while running $callback
     */
    private function missesDuring(callable $callback): array
    {
        $misses = [];
        Event::listen(CacheMissed::class, function (CacheMissed $event) use (&$misses): void {
            $misses[] = $event->key;
        });

        $callback();
        Event::forget(CacheMissed::class);

        return $misses;
    }

    public function test_clear_invalidates_cache(): void
    {
        $dir = sys_get_temp_dir().'/manual-clear-'.bin2hex(random_bytes(4));
        config(['cache.stores.file' => ['driver' => 'file', 'path' => $dir]]);
        Cache::forgetDriver('file');
        $this->useCachedFixture('file');

        try {
            $repository = app(ManualRepository::class);
            $tree = $repository->tree('hu');
            $before = $repository->cacheKey('tree', 'hu');

            $this->assertSame([], $this->missesDuring(fn () => $repository->tree('hu')), 'warm cache');

            [$exit, $output] = [Artisan::call('manual:clear'), Artisan::output()];
            $this->assertSame(0, $exit);
            $this->assertStringContainsString('Manual cache cleared (generation 1).', $output);

            // The same instance (the command ran in this container) and a fresh one both rebuild.
            $after = $repository->cacheKey('tree', 'hu');
            $this->assertNotSame($before, $after);
            $this->assertSame([$after], $this->missesDuring(fn () => $this->assertSame($tree, $repository->tree('hu'))));

            app()->forgetInstance(ManualRepository::class);
            $fresh = app(ManualRepository::class);
            $this->assertSame(1, $fresh->generation());
            $this->assertSame([], $this->missesDuring(fn () => $fresh->tree('hu')), 'rebuilt entry is cached again');

            Artisan::call('manual:clear');
            $this->assertStringContainsString('(generation 2)', Artisan::output());
        } finally {
            (new Filesystem)->deleteDirectory($dir);
        }
    }

    public function test_clear_also_invalidates_the_search_index(): void
    {
        $this->useCachedFixture('array');
        Cache::forgetDriver('array');

        $search = app(ManualSearch::class);
        $search->search('tokenek', 'hu');
        $key = app(ManualRepository::class)->cacheKey('search', 'hu');
        $this->assertTrue(Cache::store('array')->has($key));

        Artisan::call('manual:clear');

        $this->assertNotSame($key, app(ManualRepository::class)->cacheKey('search', 'hu'));
        $misses = $this->missesDuring(fn () => $search->search('tokenek', 'hu'));
        $this->assertContains(app(ManualRepository::class)->cacheKey('search', 'hu'), $misses);
    }

    public function test_clear_works_when_the_store_cannot_increment_a_missing_key(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->expects($this->once())->method('increment')->with('manual:generation')->willReturn(false);
        $cache->expects($this->once())->method('get')->with('manual:generation')->willReturn(null);
        $cache->expects($this->once())->method('forever')->with('manual:generation', 1)->willReturn(true);

        $repository = new ManualRepository(app(ContentTree::class), app(ManualRenderer::class), $cache, 'manual');

        $this->assertSame(1, $repository->flushCache());
        $this->assertSame(1, $repository->generation(), 'no second read');
    }

    public function test_generation_is_read_once_per_instance(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->expects($this->once())->method('get')->with('manual:generation')->willReturn(4);

        $repository = new ManualRepository(app(ContentTree::class), app(ManualRenderer::class), $cache, 'manual');

        $this->assertStringContainsString(':g4:', $repository->cacheKey('tree', 'hu'));
        $this->assertStringContainsString(':g4:', $repository->cacheKey('chapter', 'hu', 'general/intro'));
    }
}
