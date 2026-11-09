<?php

declare(strict_types=1);

namespace IntrAgile\Manual\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use IntrAgile\Manual\LocaleResolver;
use IntrAgile\Manual\Tests\TestCase;

final class LocaleResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['manual.locales' => ['en', 'hu'], 'manual.source_locale' => 'en']);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function resolve(array $query = [], string $acceptLanguage = ''): string
    {
        return app(LocaleResolver::class)->resolve(
            Request::create('/x', 'GET', $query, server: ['HTTP_ACCEPT_LANGUAGE' => $acceptLanguage]),
        );
    }

    public function test_query_wins_over_the_header(): void
    {
        $this->assertSame('en', $this->resolve(['locale' => 'en'], 'hu'));
    }

    public function test_unallowed_query_yields_to_an_allowed_header_language(): void
    {
        $this->assertSame('en', $this->resolve(['locale' => 'de'], 'en'));
    }

    public function test_first_allowed_header_language_wins_over_an_earlier_unallowed_one(): void
    {
        config(['manual.locales' => ['hu', 'en'], 'manual.source_locale' => 'hu', 'manual.fallback' => ['en']]);

        $this->assertSame('en', $this->resolve(acceptLanguage: 'de, en;q=0.9'));
    }

    public function test_unallowed_request_is_passed_on_when_nothing_allowed_was_asked_for(): void
    {
        // The repository applies the allowlist and reports the fallback.
        $this->assertSame('de', $this->resolve(['locale' => 'de'], 'fr'));
        $this->assertSame('de', $this->resolve(acceptLanguage: 'de-DE, fr;q=0.9'));
    }

    public function test_app_locale_does_not_compete_with_a_client_request(): void
    {
        config(['manual.locales' => ['hu'], 'manual.source_locale' => 'hu']);
        App::setLocale('hu');

        $this->assertSame('en', $this->resolve(['locale' => 'en']));
    }

    public function test_disallowed_app_locale_gives_the_source_locale(): void
    {
        App::setLocale('de');

        $this->assertSame('en', $this->resolve());
    }

    public function test_query_is_normalised(): void
    {
        $this->assertSame('hu', $this->resolve(['locale' => 'HU']));
        $this->assertSame('hu', $this->resolve(['locale' => 'hu_HU']));
    }

    public function test_region_tag_resolves_to_its_primary_subtag(): void
    {
        $this->assertSame('hu', $this->resolve(acceptLanguage: 'hu-HU,hu;q=0.9,en;q=0.8'));
        $this->assertSame('hu', $this->resolve(['locale' => 'hu-HU']));
    }

    public function test_unconfigured_region_tag_is_requested_as_its_language(): void
    {
        $this->assertSame('de', $this->resolve(acceptLanguage: 'de-DE'));
        $this->assertSame('hu', $this->resolve(acceptLanguage: 'de-DE,hu;q=0.9'));
    }

    public function test_configured_region_locale_matches_the_full_tag_in_its_configured_spelling(): void
    {
        config(['manual.locales' => ['en', 'pt', 'pt_BR']]);

        $this->assertSame('pt_BR', $this->resolve(acceptLanguage: 'pt-br,pt;q=0.9'));
        $this->assertSame('pt', $this->resolve(acceptLanguage: 'pt-PT'));
    }

    public function test_source_locale_counts_as_allowed(): void
    {
        config(['manual.locales' => [], 'manual.source_locale' => 'pt_BR']);

        $this->assertSame('pt_BR', $this->resolve(acceptLanguage: 'pt-BR'));
    }

    public function test_malformed_query_falls_through_to_the_header(): void
    {
        $this->assertSame('hu', $this->resolve(['locale' => '../../etc'], 'hu'));
        $this->assertSame('hu', $this->resolve(['locale' => ''], 'hu'));
        $this->assertSame('hu', $this->resolve(['locale' => 'english'], 'hu'));
    }

    public function test_non_string_query_falls_through(): void
    {
        $this->assertSame('en', $this->resolve(['locale' => ['hu']]));
    }

    public function test_malformed_header_tags_are_skipped(): void
    {
        $this->assertSame('hu', $this->resolve(acceptLanguage: '*, x1, hu;q=0.5'));
    }

    public function test_long_tag_offers_its_primary_subtag(): void
    {
        $this->assertSame('zh', $this->resolve(acceptLanguage: 'zh-Hant-TW'));
    }

    public function test_app_locale_is_used_when_nothing_else_is_given(): void
    {
        App::setLocale('hu');

        $this->assertSame('hu', $this->resolve());
        $this->assertSame('hu', $this->resolve(['locale' => '../etc'], '*'));
    }

    public function test_header_wins_over_app_locale(): void
    {
        App::setLocale('hu');

        $this->assertSame('en', $this->resolve(acceptLanguage: 'en'));
    }

    public function test_source_locale_is_the_last_resort(): void
    {
        App::setLocale('not a locale');

        $this->assertSame('en', $this->resolve());
    }

    public function test_header_languages_with_zero_quality_are_ignored(): void
    {
        config(['manual.locales' => ['hu', 'en'], 'manual.source_locale' => 'hu', 'manual.fallback' => ['en']]);

        // en;q=0 means "not en": de is passed on and served through the fallback chain.
        $this->assertSame('de', $this->resolve(acceptLanguage: 'en;q=0, de;q=0.5'));

        App::setLocale('fr');
        $this->assertSame('hu', $this->resolve(acceptLanguage: 'en;q=0'), 'nothing acceptable: source locale');
    }
}
