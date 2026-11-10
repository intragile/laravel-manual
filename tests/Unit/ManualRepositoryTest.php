<?php

declare(strict_types=1);

namespace IntrAgile\Manual\Tests\Unit;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use IntrAgile\Manual\Content\ContentTree;
use IntrAgile\Manual\Content\ManualRepository;
use IntrAgile\Manual\Facades\Manual;
use IntrAgile\Manual\Rendering\ManualRenderer;
use IntrAgile\Manual\Tests\TestCase;

final class ManualRepositoryTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useContent(__DIR__.'/../Fixtures/manual');
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $this->removeDir($this->tmp);
        }

        parent::tearDown();
    }

    private function useContent(string $path): void
    {
        config([
            'manual.path' => $path,
            'manual.locales' => ['hu', 'en'],
            'manual.source_locale' => 'hu',
            'manual.fallback' => ['en'],
            'manual.cache.store' => 'array',
        ]);

        app()->forgetInstance(ContentTree::class);
        app()->forgetInstance(ManualRepository::class);
        app()->forgetInstance(ManualRenderer::class);
        Cache::forgetDriver('array');
    }

    private function repo(): ManualRepository
    {
        return app()->make(ManualRepository::class);
    }

    private function copyFixture(): string
    {
        $this->tmp = sys_get_temp_dir().'/manual-test-'.bin2hex(random_bytes(4));
        $this->copyDir(__DIR__.'/../Fixtures/manual', $this->tmp);

        return $this->tmp;
    }

    private function copyDir(string $from, string $to): void
    {
        mkdir($to, 0777, true);

        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            is_dir($from.'/'.$entry)
                ? $this->copyDir($from.'/'.$entry, $to.'/'.$entry)
                : copy($from.'/'.$entry, $to.'/'.$entry);
        }
    }

    private function removeDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;
            ! is_link($path) && is_dir($path) ? $this->removeDir($path) : unlink($path);
        }

        rmdir($dir);
    }

    public function test_tree_orders_and_numbers(): void
    {
        $tree = $this->repo()->tree('hu');

        $this->assertSame(['general', 'advanced'], array_column($tree, 'id'));
        $this->assertSame('home', $tree[0]['icon']);
        $this->assertSame(['1.1', '1.2'], array_column($tree[0]['chapters'], 'number'));
        $this->assertSame('2.1', $tree[1]['chapters'][0]['number']);
        $this->assertSame('general/intro', $tree[0]['chapters'][0]['slug']);
        $this->assertSame('Bevezetés', $tree[0]['chapters'][0]['title']);
        $this->assertSame('Ez a bevezető fejezet első bekezdése.', $tree[0]['chapters'][0]['summary']);
    }

    public function test_section_titles_are_localized(): void
    {
        $this->assertSame('Általános', $this->repo()->tree('hu')[0]['title']);
        $this->assertSame('General', $this->repo()->tree('en')[0]['title']);
        // en lacks "advanced": falls back to hu.
        $this->assertSame('Haladó', $this->repo()->tree('en')[1]['title']);
    }

    public function test_section_title_falls_back_to_id_as_last_resort(): void
    {
        $dir = $this->copyFixture();
        unlink($dir.'/hu/_sections.php');
        unlink($dir.'/en/_sections.php');
        $this->useContent($dir);

        $this->assertSame('general', $this->repo()->tree('hu')[0]['title']);
    }

    public function test_chapter_has_neighbours_position_total_and_reading_time(): void
    {
        $chapter = $this->repo()->chapter('general/setup', 'hu');

        $this->assertNotNull($chapter);
        $this->assertSame('general', $chapter['section']);
        $this->assertSame('1.2', $chapter['number']);
        $this->assertSame(2, $chapter['position']);
        $this->assertSame(3, $chapter['total']);
        $this->assertSame(1, $chapter['readingMinutes']);
        $this->assertSame(['slug' => 'general/intro', 'title' => 'Bevezetés'], $chapter['prev']);
        $this->assertSame(['slug' => 'advanced/tuning', 'title' => 'Finomhangolás'], $chapter['next']);
        $this->assertSame('hu', $chapter['locale']);
        $this->assertFalse($chapter['fallback']);
        $this->assertSame([['level' => 2, 'text' => 'Részletek', 'anchor' => 'reszletek']], $chapter['headings']);
        $this->assertStringContainsString('<h1', $chapter['html']);

        $first = $this->repo()->chapter('general/intro', 'hu');
        $this->assertNotNull($first);
        $this->assertNull($first['prev']);
    }

    public function test_tokens_are_rendered_in_chapters(): void
    {
        $chapter = $this->repo()->chapter('general/intro', 'en');

        $this->assertNotNull($chapter);
        $this->assertStringContainsString('data-slug="general/setup"', $chapter['html']);
        $this->assertStringContainsString('the setup', $chapter['html']);
        $this->assertStringNotContainsString('[[manual:', $chapter['html']);
    }

    public function test_falls_back_to_source_locale_when_translation_missing(): void
    {
        $chapter = $this->repo()->chapter('advanced/tuning', 'en');

        $this->assertNotNull($chapter);
        $this->assertSame('hu', $chapter['locale']);
        $this->assertTrue($chapter['fallback']);
        $this->assertSame('Finomhangolás', $chapter['title']);

        $tree = $this->repo()->tree('en');
        $this->assertSame('advanced/tuning', $tree[1]['chapters'][0]['slug']);
    }

    public function test_markdown_returns_source_and_locale(): void
    {
        $markdown = $this->repo()->markdown('advanced/tuning', 'en');

        $this->assertNotNull($markdown);
        $this->assertSame('hu', $markdown['locale']);
        $this->assertStringStartsWith('# Finomhangolás', $markdown['markdown']);
        $this->assertNull($this->repo()->markdown('../_meta', 'hu'));
    }

    public function test_unknown_or_traversal_slug_is_null(): void
    {
        foreach (['../_meta', 'general/../../x', 'general/../../.env', 'nope/nope', 'general', '', 'general/intro/../intro', "general/intro\0"] as $slug) {
            $this->assertNull($this->repo()->chapter($slug, 'hu'), $slug);
            $this->assertNull($this->repo()->markdown($slug, 'hu'), $slug);
        }

        $tree = app()->make(ContentTree::class);
        $this->assertFalse($tree->isKnown('../_meta'));
        $this->assertTrue($tree->isKnown('general/intro'));
    }

    public function test_disallowed_locale_is_served_through_the_fallback_list(): void
    {
        foreach (['../x', 'xx', 'de', '../../etc'] as $locale) {
            $chapter = $this->repo()->chapter('general/intro', $locale);

            $this->assertNotNull($chapter, $locale);
            $this->assertSame('en', $chapter['locale']);
            $this->assertTrue($chapter['fallback']);
            $this->assertSame('General', $this->repo()->tree($locale)[0]['title']);

            $missing = $this->repo()->chapter('advanced/tuning', $locale);
            $this->assertNotNull($missing, $locale);
            $this->assertSame('hu', $missing['locale']);
            $this->assertTrue($missing['fallback']);
        }

        $markdown = $this->repo()->markdown('general/intro', '../x');
        $this->assertNotNull($markdown);
        $this->assertSame('en', $markdown['locale']);

        $markdown = $this->repo()->markdown('advanced/tuning', '../x');
        $this->assertNotNull($markdown);
        $this->assertSame('hu', $markdown['locale']);
    }

    public function test_disallowed_locale_with_an_empty_fallback_serves_the_source_locale(): void
    {
        config(['manual.fallback' => []]);
        app()->forgetInstance(ContentTree::class);
        app()->forgetInstance(ManualRepository::class);

        foreach (['de', '../x'] as $locale) {
            $chapter = $this->repo()->chapter('general/intro', $locale);

            $this->assertNotNull($chapter, $locale);
            $this->assertSame('hu', $chapter['locale']);
            $this->assertTrue($chapter['fallback']);
            $this->assertSame('Általános', $this->repo()->tree($locale)[0]['title']);
        }
    }

    public function test_disallowed_locale_never_enters_the_chain_or_a_path(): void
    {
        $tree = app()->make(ContentTree::class);

        foreach (['../x', 'de', '../../etc', "hu\0"] as $locale) {
            $this->assertSame(['en', 'hu'], $tree->chain($locale), $locale);
            $this->assertSame('en', $tree->resolveLocale($locale), $locale);
        }

        $this->assertSame(['hu', 'en'], $tree->chain('hu'));
        $this->assertSame(['en', 'hu'], $tree->chain('en'));
    }

    public function test_cache_is_invalidated_when_content_changes(): void
    {
        $dir = $this->copyFixture();
        $this->useContent($dir);

        $this->assertSame('Bevezetés', $this->repo()->tree('hu')[0]['chapters'][0]['title']);

        file_put_contents($dir.'/hu/general/intro.md', "# Új cím\n\nÚj összefoglaló, hosszabb szöveg.\n");
        app()->forgetInstance(ManualRepository::class); // fingerprint is memoized per instance (scoped per request)

        $this->assertSame('Új cím', $this->repo()->tree('hu')[0]['chapters'][0]['title']);
        $chapter = $this->repo()->chapter('general/intro', 'hu');
        $this->assertNotNull($chapter);
        $this->assertSame('Új cím', $chapter['title']);
    }

    public function test_missing_content_directory_gives_empty_tree(): void
    {
        $this->useContent(sys_get_temp_dir().'/manual-does-not-exist-'.bin2hex(random_bytes(4)));

        $this->assertSame([], $this->repo()->tree('hu'));
        $this->assertNull($this->repo()->chapter('general/intro', 'hu'));
        $this->assertSame(sha1(''), $this->repo()->fingerprint());
    }

    public function test_fingerprint_is_deterministic_and_content_sensitive(): void
    {
        $this->assertSame($this->repo()->fingerprint(), $this->repo()->fingerprint());

        $dir = $this->copyFixture();
        $this->useContent($dir);
        $before = $this->repo()->fingerprint();
        file_put_contents($dir.'/hu/general/setup.md', "# Beállítás\n\nMás.\n");
        app()->forgetInstance(ManualRepository::class);
        $this->assertNotSame($before, $this->repo()->fingerprint());
    }

    public function test_fingerprint_survives_a_symlink_to_an_ancestor_directory(): void
    {
        $dir = $this->copyFixture();

        if (! @symlink($dir, $dir.'/hu/loop') || ! @symlink('..', $dir.'/en/up')) {
            $this->markTestSkipped('Symbolic links cannot be created here.');
        }

        $this->useContent($dir);
        $before = $this->repo()->fingerprint();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $before);

        file_put_contents($dir.'/hu/general/setup.md', "# Beállítás\n\nMás, hosszabb szöveg.\n");
        app()->forgetInstance(ManualRepository::class);
        $this->assertNotSame($before, $this->repo()->fingerprint());

        $this->assertSame('Bevezetés', $this->repo()->tree('hu')[0]['chapters'][0]['title']);
    }

    public function test_cache_survives_serializable_classes_false_on_file_store(): void
    {
        $dir = sys_get_temp_dir().'/manual-cache-'.bin2hex(random_bytes(4));
        mkdir($dir);

        try {
            config([
                'cache.serializable_classes' => false,
                'cache.stores.file' => ['driver' => 'file', 'path' => $dir],
                'manual.cache.store' => 'file',
            ]);
            Cache::forgetDriver('file');
            app()->forgetInstance(ManualRepository::class);

            $first = $this->repo()->tree('hu');
            $firstChapter = $this->repo()->chapter('general/intro', 'hu');

            $hits = 0;
            Event::listen(CacheHit::class, function () use (&$hits): void {
                $hits++;
            });

            $this->assertSame($first, $this->repo()->tree('hu'));
            $this->assertSame(1, $hits, 'tree must come from cache');
            $this->assertSame($firstChapter, $this->repo()->chapter('general/intro', 'hu'));
            $this->assertSame(2, $hits, 'chapter must come from cache');
            $this->assertNotNull($firstChapter);
            $this->assertNotSame([], $first);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_wrong_shaped_cache_entry_is_rebuilt(): void
    {
        $expected = $this->repo()->chapter('general/intro', 'hu');
        $expectedTree = $this->repo()->tree('hu');

        $store = Cache::store('array');
        $store->put($this->repo()->cacheKey('chapter', 'hu', 'general/intro'), ['slug' => 5], 60);
        $store->put($this->repo()->cacheKey('tree', 'hu'), 'garbage', 60);

        $this->assertSame($expected, $this->repo()->chapter('general/intro', 'hu'));
        $this->assertSame($expectedTree, $this->repo()->tree('hu'));
    }

    public function test_facade_proxies_the_repository(): void
    {
        $this->assertSame($this->repo()->tree('hu'), Manual::tree('hu'));
        $this->assertSame($this->repo()->chapter('general/intro', 'hu'), Manual::chapter('general/intro', 'hu'));
        $this->assertSame($this->repo()->markdown('general/intro', 'hu'), Manual::markdown('general/intro', 'hu'));
    }

    public function test_links_file_defaults_to_path_links_php(): void
    {
        $dir = $this->copyFixture();
        file_put_contents($dir.'/_links.php', "<?php return ['docs' => ['en' => 'https://example.com/docs']];");
        file_put_contents($dir.'/en/general/intro.md', "# Intro\n\nSee [[link:docs|the docs]].\n");
        $this->useContent($dir);

        $chapter = $this->repo()->chapter('general/intro', 'en');
        $this->assertNotNull($chapter);
        $this->assertStringContainsString('https://example.com/docs', $chapter['html']);
    }

    public function test_ghost_chapter_is_not_numbered_or_navigated(): void
    {
        $dir = $this->copyFixture();
        file_put_contents($dir.'/_meta.php', "<?php return ['general' => ['icon' => 'home', 'order' => 1, 'chapters' => ['intro', 'ghost', 'setup']], 'advanced' => ['icon' => 'wrench', 'order' => 2, 'chapters' => ['tuning']]];");
        $this->useContent($dir);

        $tree = $this->repo()->tree('hu');
        $this->assertSame(['1.1', '1.2'], array_column($tree[0]['chapters'], 'number'));

        $intro = $this->repo()->chapter('general/intro', 'hu');
        $setup = $this->repo()->chapter('general/setup', 'hu');
        $this->assertNotNull($intro);
        $this->assertNotNull($setup);
        $this->assertSame('general/setup', $intro['next']['slug'] ?? null);
        $this->assertSame('general/intro', $setup['prev']['slug'] ?? null);
        $this->assertSame('1.2', $setup['number']);
        $this->assertSame(2, $setup['position']);
        $this->assertSame(3, $setup['total']);
        $this->assertNull($this->repo()->chapter('general/ghost', 'hu'));
        $this->assertNull($this->repo()->markdown('general/ghost', 'hu'));
        $this->assertTrue(app()->make(ContentTree::class)->isKnown('general/ghost'));
    }

    public function test_config_only_change_invalidates_cache(): void
    {
        $dir = $this->copyFixture();
        file_put_contents($dir.'/hu/general/intro.md', "# Bevezetés\n\nElső.\n\n:::tip\n\nTipp szöveg.\n\n:::\n");
        $this->useContent($dir);

        $withCallout = $this->repo()->chapter('general/intro', 'hu');
        $keyBefore = $this->repo()->cacheKey('chapter', 'hu', 'general/intro');

        config(['manual.callouts' => []]);
        app()->forgetInstance(ManualRepository::class);
        app()->forgetInstance(ManualRenderer::class);

        $without = $this->repo()->chapter('general/intro', 'hu');
        $this->assertNotNull($withCallout);
        $this->assertNotNull($without);
        $this->assertNotSame($withCallout['html'], $without['html']);

        $keyAfterCallouts = $this->repo()->cacheKey('chapter', 'hu', 'general/intro');
        $this->assertNotSame($keyBefore, $keyAfterCallouts);

        config(['manual.fallback' => []]);
        app()->forgetInstance(ContentTree::class);
        app()->forgetInstance(ManualRepository::class);
        $this->assertNotSame($keyAfterCallouts, $this->repo()->cacheKey('chapter', 'hu', 'general/intro'));
    }
}
