<?php

declare(strict_types=1);

namespace IntrAgile\Manual\Tests\Feature;

use IntrAgile\Manual\Tests\TestCase;

final class RoutesEnabledFlagTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        // An env-driven flag arrives as a string.
        $app['config']->set('manual.routes.enabled', 'false');
        $app['config']->set('manual.access', 'public');
        $app['config']->set('manual.path', __DIR__.'/../Fixtures/manual');
    }

    public function test_a_false_string_disables_the_routes(): void
    {
        $this->getJson('/manual/chapter?slug=general/intro')->assertNotFound();
        $this->assertNull(app('router')->getRoutes()->getByName('manual.chapter'));
    }
}
