<?php

declare(strict_types=1);

namespace IntrAgile\Manual\Tests\Unit;

use IntrAgile\Manual\Facades\Manual;
use IntrAgile\Manual\ManualManager;
use IntrAgile\Manual\Search\ManualSearch;
use IntrAgile\Manual\Tests\TestCase;

final class ManualManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'manual.path' => __DIR__.'/../Fixtures/search',
            'manual.locales' => ['hu', 'en'],
            'manual.source_locale' => 'hu',
            'manual.cache.store' => 'array',
        ]);
    }

    public function test_manager_is_the_scoped_facade_root(): void
    {
        $this->assertSame(app(ManualManager::class), app(ManualManager::class));
        $this->assertSame(app(ManualManager::class), Manual::getFacadeRoot());
    }

    public function test_facade_search_matches_the_search_service(): void
    {
        $expected = app(ManualSearch::class)->search('tokenek', 'hu', 2);

        $this->assertNotSame([], $expected);
        $this->assertSame($expected, Manual::search('tokenek', 'hu', 2));
    }

    public function test_spy_intercepts_search(): void
    {
        $spy = Manual::spy();

        Manual::search('tokenek', 'hu');

        $spy->shouldHaveReceived('search')->once()->with('tokenek', 'hu');
    }

    public function test_swap_replaces_search(): void
    {
        $fake = $this->createStub(ManualManager::class);
        $fake->method('search')->willReturn([['slug' => 'x/y', 'section' => 'x', 'title' => 'Y', 'snippet' => '', 'score' => 1]]);
        Manual::swap($fake);

        $this->assertSame('x/y', Manual::search('anything', 'hu')[0]['slug']);
    }
}
