<?php

declare(strict_types=1);

namespace IntrAgile\Manual;

use Illuminate\Support\ServiceProvider;

final class ManualServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/manual.php', 'manual');
    }

    public function boot(): void
    {
        // Defaults mirror config/manual.php: a published config that overrides
        // only part of `routes` replaces the whole array (the merge is shallow).
        $enabled = filter_var(config('manual.routes.enabled', true), FILTER_VALIDATE_BOOL);

        if ($enabled && ! $this->app->routesAreCached()) {
            // TODO(Task 5): register the manual routes here, inside
            // Route::middleware(config('manual.routes.middleware', ['api']))->group(...).
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/manual.php' => config_path('manual.php'),
            ], 'manual-config');
        }
    }
}
