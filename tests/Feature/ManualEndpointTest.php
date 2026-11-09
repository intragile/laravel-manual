<?php

declare(strict_types=1);

namespace IntrAgile\Manual\Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\ValidationException;
use IntrAgile\Manual\Tests\TestCase;

final class ManualEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'manual.path' => __DIR__.'/../Fixtures/manual',
            'manual.locales' => ['en', 'hu'],
            'manual.source_locale' => 'hu',
            'manual.fallback' => ['en'],
            'manual.access' => 'public',
            'manual.cache.store' => 'array',
        ]);
    }

    public function test_index_lists_sections_and_chapters_in_order(): void
    {
        $this->getJson('/manual?locale=hu')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    [
                        'id' => 'general',
                        'icon' => 'home',
                        'title' => 'Általános',
                        'chapters' => [
                            ['slug' => 'general/intro', 'number' => '1.1', 'title' => 'Bevezetés', 'summary' => 'Ez a bevezető fejezet első bekezdése.'],
                            ['slug' => 'general/setup', 'number' => '1.2', 'title' => 'Beállítás', 'summary' => 'A beállítás összefoglalója.'],
                        ],
                    ],
                    [
                        'id' => 'advanced',
                        'icon' => 'wrench',
                        'title' => 'Haladó',
                        'chapters' => [
                            ['slug' => 'advanced/tuning', 'number' => '2.1', 'title' => 'Finomhangolás', 'summary' => 'A finomhangolás összefoglalója.'],
                        ],
                    ],
                ],
                'locale' => 'hu',
                'fallback' => false,
            ]);
    }

    public function test_chapter_has_the_full_shape(): void
    {
        $response = $this->getJson('/manual/chapter?slug=general/intro&locale=hu')->assertOk();

        $response->assertJsonPath('data.slug', 'general/intro')
            ->assertJsonPath('data.number', '1.1')
            ->assertJsonPath('data.title', 'Bevezetés')
            ->assertJsonPath('data.section', 'general')
            ->assertJsonPath('data.position', 1)
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.readingMinutes', 1)
            ->assertJsonPath('data.prev', null)
            ->assertJsonPath('data.next', ['slug' => 'general/setup', 'title' => 'Beállítás'])
            ->assertJsonPath('data.headings.0', ['anchor' => 'elso-lepesek', 'text' => 'Első lépések', 'level' => 2])
            ->assertJsonPath('data.locale', 'hu')
            ->assertJsonPath('data.fallback', false);

        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertSame(
            ['slug', 'number', 'title', 'summary', 'section', 'position', 'total', 'readingMinutes', 'html', 'headings', 'prev', 'next', 'locale', 'fallback'],
            array_keys($data),
        );
        $this->assertIsString($data['number']);
        $this->assertIsString($data['html']);
        $this->assertStringContainsString('<p>', $data['html']);
    }

    public function test_missing_translation_falls_back_to_the_source_locale(): void
    {
        $this->getJson('/manual/chapter?slug=advanced/tuning&locale=en')
            ->assertOk()
            ->assertJsonPath('data.locale', 'hu')
            ->assertJsonPath('data.fallback', true)
            ->assertJsonPath('data.title', 'Finomhangolás');
    }

    public function test_translated_chapter_is_served_in_the_requested_locale(): void
    {
        $this->getJson('/manual/chapter?slug=general/intro&locale=en')
            ->assertOk()
            ->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.fallback', false)
            ->assertJsonPath('data.title', 'Introduction');
    }

    public function test_accept_language_region_tag_resolves_to_its_language(): void
    {
        config(['manual.source_locale' => 'en']);

        $this->getJson('/manual', ['Accept-Language' => 'hu-HU,hu;q=0.9'])
            ->assertOk()
            ->assertJsonPath('locale', 'hu')
            ->assertJsonPath('fallback', false)
            ->assertJsonPath('data.0.title', 'Általános');

        $this->getJson('/manual/chapter?slug=general/intro', ['Accept-Language' => 'hu-HU'])
            ->assertOk()
            ->assertJsonPath('data.locale', 'hu')
            ->assertJsonPath('data.fallback', false);
    }

    public function test_query_locale_wins_over_accept_language(): void
    {
        $this->getJson('/manual?locale=en', ['Accept-Language' => 'hu'])
            ->assertOk()
            ->assertJsonPath('locale', 'en')
            ->assertJsonPath('data.0.title', 'General');
    }

    public function test_unconfigured_language_is_served_from_the_source_with_fallback(): void
    {
        // The Review Focus case: only Hungarian content is configured, the UI asks for English.
        config(['manual.locales' => ['hu'], 'manual.source_locale' => 'hu']);

        $this->getJson('/manual?locale=en')
            ->assertOk()
            ->assertJsonPath('locale', 'hu')
            ->assertJsonPath('fallback', true)
            ->assertJsonPath('data.0.chapters.0.title', 'Bevezetés');

        $this->getJson('/manual/chapter?slug=general/intro', ['Accept-Language' => 'en-US,en;q=0.9'])
            ->assertOk()
            ->assertJsonPath('data.locale', 'hu')
            ->assertJsonPath('data.fallback', true);

        $this->getJson('/manual/search?q=bevezető&locale=en')
            ->assertOk()
            ->assertJsonPath('locale', 'hu')
            ->assertJsonPath('fallback', true)
            ->assertJsonPath('data.0.slug', 'general/intro');
    }

    public function test_first_allowed_accept_language_is_served(): void
    {
        config(['manual.locales' => ['hu', 'en'], 'manual.source_locale' => 'hu', 'manual.fallback' => ['en']]);

        $this->getJson('/manual', ['Accept-Language' => 'de, en;q=0.9'])
            ->assertOk()
            ->assertJsonPath('locale', 'en')
            ->assertJsonPath('fallback', false)
            ->assertJsonPath('data.0.title', 'General');

        $this->getJson('/manual/chapter?slug=general/intro&locale=de', ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.fallback', false);
    }

    public function test_app_locale_equal_to_the_source_does_not_hide_the_fallback(): void
    {
        config(['manual.locales' => ['hu'], 'manual.source_locale' => 'hu']);
        app()->setLocale('hu');

        $this->getJson('/manual?locale=en')
            ->assertOk()
            ->assertJsonPath('locale', 'hu')
            ->assertJsonPath('fallback', true);

        $this->getJson('/manual/chapter?slug=general/intro&locale=en')
            ->assertOk()
            ->assertJsonPath('data.locale', 'hu')
            ->assertJsonPath('data.fallback', true);
    }

    public function test_allowed_app_locale_is_used_without_a_client_locale(): void
    {
        app()->setLocale('en');

        $this->getJson('/manual', ['Accept-Language' => ''])
            ->assertOk()
            ->assertJsonPath('locale', 'en')
            ->assertJsonPath('fallback', false)
            ->assertJsonPath('data.0.title', 'General');
    }

    public function test_unknown_chapter_is_404(): void
    {
        $this->getJson('/manual/chapter?slug=general/nope')->assertNotFound();
    }

    public function test_traversal_slug_is_404(): void
    {
        foreach (['../_meta', 'general/../../.env', '../../../../etc/passwd', 'hu/general/intro'] as $slug) {
            $this->getJson('/manual/chapter?slug='.urlencode($slug))->assertNotFound();
        }
    }

    public function test_chapter_requires_a_slug(): void
    {
        $this->getJson('/manual/chapter')->assertStatus(422)->assertJsonValidationErrors(['slug']);
        $this->getJson('/manual/chapter?slug[]=x')->assertStatus(422)->assertJsonValidationErrors(['slug']);
        $this->getJson('/manual/chapter?slug='.str_repeat('a', 201))->assertStatus(422)->assertJsonValidationErrors(['slug']);
    }

    public function test_search_returns_hits(): void
    {
        $this->getJson('/manual/search?q=beállítás&locale=hu')
            ->assertOk()
            ->assertJsonPath('locale', 'hu')
            ->assertJsonPath('fallback', false)
            ->assertJsonPath('data.0.slug', 'general/setup')
            ->assertJsonPath('data.0.section', 'general')
            ->assertJsonPath('data.0.title', 'Beállítás')
            ->assertJsonStructure(['data' => [['slug', 'section', 'title', 'snippet', 'score']], 'locale', 'fallback']);
    }

    public function test_search_without_hits_is_an_empty_list(): void
    {
        $this->getJson('/manual/search?q=zebracsikos&locale=hu')
            ->assertOk()
            ->assertExactJson(['data' => [], 'locale' => 'hu', 'fallback' => false]);
    }

    public function test_search_validates_the_query(): void
    {
        $this->getJson('/manual/search')->assertStatus(422)->assertJsonValidationErrors(['q']);
        $this->getJson('/manual/search?q[]=x')->assertStatus(422)->assertJsonValidationErrors(['q']);
        $this->getJson('/manual/search?q='.str_repeat('a', 201))->assertStatus(422)->assertJsonValidationErrors(['q']);
        $this->getJson('/manual/search?q='.str_repeat('a', 200))->assertOk();
    }

    public function test_locale_is_validated(): void
    {
        $this->getJson('/manual?locale='.str_repeat('a', 21))->assertStatus(422)->assertJsonValidationErrors(['locale']);
        $this->getJson('/manual/chapter?slug=general/intro&locale[]=hu')->assertStatus(422)->assertJsonValidationErrors(['locale']);
        $this->getJson('/manual/search?q=abc&locale[]=hu')->assertStatus(422)->assertJsonValidationErrors(['locale']);
    }

    public function test_malformed_locale_falls_through_to_the_next_candidate(): void
    {
        $this->getJson('/manual?locale=../etc', ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertJsonPath('locale', 'en');
    }

    public function test_validation_failure_is_json_without_accept_header(): void
    {
        $this->get('/manual/search')
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonValidationErrors(['q']);
    }

    public function test_not_found_is_json_without_accept_header(): void
    {
        $this->get('/manual/chapter?slug=general/nope')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_host_exception_rendering_is_honoured(): void
    {
        $handler = app(ExceptionHandler::class);
        $this->assertInstanceOf(Handler::class, $handler);
        $handler->renderable(static fn (ValidationException $e) => response()->json(['problem' => true], 422));

        $this->get('/manual/search')
            ->assertStatus(422)
            ->assertExactJson(['problem' => true]);
    }

    public function test_host_wide_without_wrapping_does_not_change_the_shape(): void
    {
        JsonResource::withoutWrapping();

        try {
            $this->getJson('/manual?locale=hu')->assertOk()->assertJsonPath('data.0.id', 'general')->assertJsonPath('locale', 'hu');
            $this->getJson('/manual/chapter?slug=general/intro')->assertOk()->assertJsonPath('data.slug', 'general/intro');
            $this->getJson('/manual/search?q=beállítás&locale=hu')->assertOk()->assertJsonPath('data.0.slug', 'general/setup');
        } finally {
            JsonResource::wrap('data');
        }
    }

    public function test_routes_are_named(): void
    {
        $this->assertSame('/manual', parse_url(route('manual.index'), PHP_URL_PATH));
        $this->assertSame('/manual/chapter', parse_url(route('manual.chapter'), PHP_URL_PATH));
        $this->assertSame('/manual/search', parse_url(route('manual.search'), PHP_URL_PATH));
    }
}
