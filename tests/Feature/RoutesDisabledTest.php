<?php

declare(strict_types=1);

namespace IntrAgile\Manual\Tests\Feature;

use Illuminate\Support\Facades\Route;
use IntrAgile\Manual\Facades\Manual;
use IntrAgile\Manual\Tests\TestCase;

final class RoutesDisabledTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('manual.routes.enabled', false);
        $app['config']->set('manual.access', 'public');
        $app['config']->set('manual.path', __DIR__.'/../Fixtures/manual');
    }

    public function test_nothing_is_registered_until_the_host_asks(): void
    {
        $this->getJson('/manual')->assertNotFound();

        Route::middleware('api')->prefix('api/v1')->group(static fn () => Manual::routes());

        $this->getJson('/api/v1/manual')->assertOk();
        $this->getJson('/api/v1/manual/chapter?slug=general/intro')->assertOk()->assertJsonPath('data.slug', 'general/intro');
    }
}
