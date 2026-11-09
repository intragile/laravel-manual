<?php

declare(strict_types=1);

namespace IntrAgile\Manual\Tests\Feature;

use IntrAgile\Manual\Tests\TestCase;

final class PartialRoutesConfigTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        // A published config that overrides only part of `routes`: the shallow
        // config merge drops every other key of the array.
        $app['config']->set('manual', [
            'routes' => ['prefix' => 'help'],
            'access' => 'public',
            'path' => __DIR__.'/../Fixtures/manual',
        ]);
    }

    public function test_routes_stay_enabled_when_the_override_omits_the_flag(): void
    {
        $this->getJson('/help/chapter?slug=general/intro')->assertOk()->assertJsonPath('data.slug', 'general/intro');
    }

    public function test_the_api_middleware_group_is_still_applied(): void
    {
        $route = app('router')->getRoutes()->getByName('manual.chapter');

        $this->assertNotNull($route);
        $this->assertContains('api', $route->gatherMiddleware());
    }
}
