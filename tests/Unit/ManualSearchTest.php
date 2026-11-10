<?php

declare(strict_types=1);

namespace IntrAgile\Manual\Tests\Unit;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use IntrAgile\Manual\Content\ContentTree;
use IntrAgile\Manual\Content\ManualRepository;
use IntrAgile\Manual\Rendering\ManualRenderer;
use IntrAgile\Manual\Search\ManualSearch;
use IntrAgile\Manual\Tests\TestCase;

final class ManualSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->useContent(__DIR__.'/../Fixtures/search');
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

        foreach ([ContentTree::class, ManualRepository::class, ManualRenderer::class, ManualSearch::class] as $abstract) {
            app()->forgetInstance($abstract);
        }
        Cache::forgetDriver('array');
    }

    private function search(): ManualSearch
    {
        return app()->make(ManualSearch::class);
    }

    /**
     * @param  list<array{slug: string, section: string, title: string, snippet: string, score: int}>  $hits
     * @return list<string>
     */
    private function slugs(array $hits): array
    {
        return array_column($hits, 'slug');
    }

    public function test_title_hits_outrank_body_hits(): void
    {
        $hits = $this->search()->search('token', 'hu');

        $this->assertSame(['guide/tokens', 'guide/channels'], $this->slugs($hits));
        $this->assertGreaterThan($hits[1]['score'], $hits[0]['score']);
        $this->assertSame('API tokenek', $hits[0]['title']);
        $this->assertSame('guide', $hits[0]['section']);
    }

    public function test_all_terms_must_match(): void
    {
        $this->assertSame(['guide/tokens', 'guide/channels'], $this->slugs($this->search()->search('api token', 'hu')));
        $this->assertSame(['guide/channels'], $this->slugs($this->search()->search('api csatorna', 'hu')));
        $this->assertSame([], $this->search()->search('token zzzzzz', 'hu'));
    }

    public function test_short_query_returns_nothing(): void
    {
        $this->assertSame([], $this->search()->search('ap', 'hu'));
    }

    public function test_blank_or_punctuation_queries_return_nothing(): void
    {
        $this->assertSame([], $this->search()->search('', 'hu'));
        $this->assertSame([], $this->search()->search("  \t\n ", 'hu'));
        $this->assertSame([], $this->search()->search('!!! ??? ---', 'hu'));
    }

    public function test_hungarian_inflection_and_missing_accents_match(): void
    {
        $this->assertSame('guide/tokens', $this->search()->search('tokeneket', 'hu')[0]['slug']);
        $this->assertContains('guide/tokens', $this->slugs($this->search()->search('api token', 'hu')));
        $this->assertSame(['guide/channels'], $this->slugs($this->search()->search('ertesites', 'hu')));
        $this->assertSame(['guide/channels'], $this->slugs($this->search()->search('Értesítések', 'hu')));
    }

    public function test_snippet_contains_term_and_ellipsis(): void
    {
        $hit = $this->search()->search('webhook', 'hu')[0];

        $this->assertStringStartsWith('…', $hit['snippet']);
        $this->assertStringContainsString('webhook', $hit['snippet']);
        $this->assertStringNotContainsString('<', $hit['snippet']);

        $zebra = $this->search()->search('zebracsik', 'hu')[0];
        $this->assertSame('guide/notes', $zebra['slug']);
        $this->assertStringStartsWith('…', $zebra['snippet']);
        $this->assertStringContainsString('zebracsík', $zebra['snippet']);
    }

    public function test_snippet_never_splits_a_utf8_character(): void
    {
        foreach (['zebracsik', 'koszivu', 'tuzorseg', 'szo'] as $query) {
            foreach ($this->search()->search($query, 'hu') as $hit) {
                $this->assertTrue(mb_check_encoding($hit['snippet'], 'UTF-8'), $query);
                $this->assertLessThanOrEqual(222, mb_strlen($hit['snippet']), $query);
            }
        }

        $hit = $this->search()->search('zebracsik', 'hu')[0];
        $this->assertStringEndsWith('…', $hit['snippet']);
        $this->assertStringContainsString('ő', $hit['snippet']);
        $this->assertStringContainsString('ű', $hit['snippet']);
    }

    public function test_limit_defaults_to_config_and_is_clamped(): void
    {
        $this->assertCount(1, $this->search()->search('token', 'hu', 0));
        $this->assertCount(1, $this->search()->search('token', 'hu', -5));
        $this->assertCount(2, $this->search()->search('token', 'hu', 50));

        config(['manual.search.limit' => 1]);
        app()->forgetInstance(ManualSearch::class);
        $this->assertCount(1, $this->search()->search('token', 'hu'));
    }

    public function test_duplicate_terms_are_collapsed(): void
    {
        $this->assertSame(
            $this->search()->search('token', 'hu'),
            $this->search()->search('token TOKEN  token', 'hu'),
        );
    }

    public function test_query_is_truncated_not_rejected(): void
    {
        $this->assertSame([], $this->search()->search(str_repeat(' ', 250).'token', 'hu'));
        $this->assertSame(['guide/tokens', 'guide/channels'], $this->slugs($this->search()->search('token '.str_repeat(' ', 300).'zzzzzz', 'hu')));
    }

    public function test_fallback_chapters_are_searchable_with_normal_slugs(): void
    {
        $hits = $this->search()->search('csatorna', 'en');

        $this->assertSame(['guide/channels'], $this->slugs($hits));
        $this->assertSame('Értesítési csatornák', $hits[0]['title']);
    }

    public function test_english_uses_the_english_stemmer(): void
    {
        $this->assertSame('guide/tokens', $this->search()->search('lists', 'en')[0]['slug']);
        $this->assertSame('guide/tokens', $this->search()->search('notification', 'en')[0]['slug']);
    }

    public function test_missing_or_invalid_stemmer_means_no_stemming(): void
    {
        config(['manual.stemmers' => ['hu' => 'No\\Such\\Stemmer', 'en' => \stdClass::class]]);
        app()->forgetInstance(ManualSearch::class);

        $this->assertSame([], $this->search()->search('tokeneket', 'hu'));
        $this->assertSame([], $this->search()->search('lists', 'en'));
        $this->assertSame('guide/tokens', $this->search()->search('tokenek', 'hu')[0]['slug']);
    }

    public function test_unknown_locale_falls_back_through_the_fallback_list(): void
    {
        $this->assertSame('guide/tokens', $this->search()->search('lists', 'de')[0]['slug']);
        $this->assertSame([], $this->search()->search('tokeneket', 'de'));
    }

    public function test_unknown_locale_with_an_empty_fallback_uses_the_source_locale(): void
    {
        config(['manual.fallback' => []]);
        foreach ([ContentTree::class, ManualRepository::class, ManualSearch::class] as $abstract) {
            app()->forgetInstance($abstract);
        }

        $this->assertSame('guide/tokens', $this->search()->search('tokeneket', 'de')[0]['slug']);
    }

    public function test_index_is_cached_under_the_search_key_as_arrays(): void
    {
        $this->search()->search('token', 'hu');

        $key = app(ManualRepository::class)->cacheKey('search', 'hu');
        $this->assertStringContainsString(':search:hu:', $key);

        $cached = Cache::store('array')->get($key);
        $this->assertIsArray($cached);
        $this->assertNotSame([], $cached);
        $this->assertSame(['guide/tokens', 'guide/channels', 'guide/notes'], array_column($cached, 'slug'));
    }

    public function test_wrong_shaped_cache_entry_is_rebuilt(): void
    {
        $key = app(ManualRepository::class)->cacheKey('search', 'hu');
        Cache::store('array')->put($key, [['slug' => 5]], 60);

        $this->assertSame('guide/tokens', $this->search()->search('token', 'hu')[0]['slug']);
    }

    public function test_cache_survives_serializable_classes_false_on_file_store(): void
    {
        $dir = sys_get_temp_dir().'/manual-search-cache-'.bin2hex(random_bytes(4));
        mkdir($dir);

        try {
            config([
                'cache.serializable_classes' => false,
                'cache.stores.file' => ['driver' => 'file', 'path' => $dir],
                'manual.cache.store' => 'file',
            ]);
            Cache::forgetDriver('file');
            foreach ([ManualRepository::class, ManualSearch::class] as $abstract) {
                app()->forgetInstance($abstract);
            }

            $first = $this->search()->search('token', 'hu');

            $hits = 0;
            Event::listen(CacheHit::class, function () use (&$hits): void {
                $hits++;
            });

            $this->assertSame($first, $this->search()->search('token', 'hu'));
            $this->assertSame(1, $hits, 'index must come from cache');
            $this->assertNotSame([], $first);
        } finally {
            foreach (glob($dir.'/*/*/*') ?: [] as $file) {
                @unlink($file);
            }
            exec('rm -rf '.escapeshellarg($dir));
        }
    }

    public function test_english_singular_and_plural_match_both_ways(): void
    {
        $dir = sys_get_temp_dir().'/manual-search-en-'.bin2hex(random_bytes(4));
        mkdir($dir.'/en/g', 0777, true);
        file_put_contents($dir.'/_meta.php', "<?php return ['g' => ['icon' => 'x', 'order' => 1, 'chapters' => ['a', 'b']]];");
        file_put_contents($dir.'/en/g/a.md', "# Saved queries\n\nSaved queries and entries live here.\n");
        file_put_contents($dir.'/en/g/b.md', "# Lookup\n\nRun a query on one entry.\n");

        try {
            config(['manual.locales' => ['en'], 'manual.source_locale' => 'en']);
            $this->useContentKeepingConfig($dir);

            foreach (['queries', 'query'] as $q) {
                $this->assertSame(['g/a', 'g/b'], $this->slugs($this->search()->search($q, 'en')), $q);
            }
            foreach (['entries', 'entry'] as $q) {
                $this->assertSame(['g/a', 'g/b'], $this->slugs($this->search()->search($q, 'en')), $q);
            }
        } finally {
            exec('rm -rf '.escapeshellarg($dir));
        }
    }

    private function useContentKeepingConfig(string $path): void
    {
        config(['manual.path' => $path, 'manual.cache.store' => 'array']);

        foreach ([ContentTree::class, ManualRepository::class, ManualRenderer::class, ManualSearch::class] as $abstract) {
            app()->forgetInstance($abstract);
        }
        Cache::forgetDriver('array');
    }

    public function test_result_count_equals_limit_when_more_documents_match(): void
    {
        $dir = sys_get_temp_dir().'/manual-search-many-'.bin2hex(random_bytes(4));
        mkdir($dir.'/en/g', 0777, true);
        $chapters = [];

        for ($i = 1; $i <= 12; $i++) {
            $chapters[] = "c$i";
            file_put_contents($dir."/en/g/c$i.md", "# Chapter $i\n\nAbout widgets, widget number $i.\n");
        }
        file_put_contents($dir.'/_meta.php', '<?php return '.var_export(['g' => ['icon' => 'x', 'order' => 1, 'chapters' => $chapters]], true).';');

        try {
            config(['manual.locales' => ['en'], 'manual.source_locale' => 'en']);
            $this->useContentKeepingConfig($dir);

            $hits = $this->search()->search('widgets', 'en', 5);
            $this->assertCount(5, $hits);
            $this->assertSame(['g/c1', 'g/c2', 'g/c3', 'g/c4', 'g/c5'], $this->slugs($hits), 'ties keep tree order');
            $this->assertCount(12, $this->search()->search('widgets', 'en', 50));
        } finally {
            exec('rm -rf '.escapeshellarg($dir));
        }
    }

    public function test_decomposed_accents_are_found_and_snippet_stays_valid(): void
    {
        $dir = sys_get_temp_dir().'/manual-search-nfd-'.bin2hex(random_bytes(4));
        mkdir($dir.'/hu/g', 0777, true);
        file_put_contents($dir.'/_meta.php', "<?php return ['g' => ['icon' => 'x', 'order' => 1, 'chapters' => ['a']]];");
        $nfd = "E\u{301}rtesi\u{301}te\u{301}si csatorna\u{301}k";
        file_put_contents($dir.'/hu/g/a.md', "# $nfd\n\nA csatorna\u{301}k be\u{301}a\u{301}llita\u{301}sa.\n");

        try {
            config(['manual.locales' => ['hu'], 'manual.source_locale' => 'hu']);
            $this->useContentKeepingConfig($dir);

            $hits = $this->search()->search('ertesites', 'hu');
            $this->assertSame(['g/a'], $this->slugs($hits));
            $this->assertTrue(mb_check_encoding($hits[0]['snippet'], 'UTF-8'));

            $hits = $this->search()->search('beallitasa', 'hu');
            $this->assertStringContainsString("be\u{301}a\u{301}llita\u{301}sa", $hits[0]['snippet']);
        } finally {
            exec('rm -rf '.escapeshellarg($dir));
        }
    }

    public function test_provider_registers_a_scoped_instance(): void
    {
        $this->assertSame(app(ManualSearch::class), app(ManualSearch::class));
    }
}
