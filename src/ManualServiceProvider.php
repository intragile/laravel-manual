<?php

declare(strict_types=1);

namespace IntrAgile\Manual;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;
use IntrAgile\Manual\Content\ContentTree;
use IntrAgile\Manual\Content\ManualRepository;
use IntrAgile\Manual\Rendering\ManualRenderer;
use IntrAgile\Manual\Search\ManualSearch;
use IntrAgile\Manual\Search\Stemmer;

final class ManualServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/manual.php', 'manual');

        $this->app->singleton(ContentTree::class, fn (): ContentTree => new ContentTree(
            self::stringConfig('manual.path'),
            self::stringList(config('manual.locales')),
            self::stringConfig('manual.source_locale', 'en'),
            self::stringList(config('manual.fallback')),
        ));

        $this->app->singleton(ManualRenderer::class, fn (Application $app): ManualRenderer => new ManualRenderer(
            self::stringList(config('manual.callouts')),
            $this->links($app->make(ContentTree::class)),
        ));

        $this->app->singleton(ManualRepository::class, function (Application $app): ManualRepository {
            $store = config('manual.cache.store');

            return new ManualRepository(
                $app->make(ContentTree::class),
                $app->make(ManualRenderer::class),
                Cache::store(is_string($store) ? $store : null),
                self::stringConfig('manual.cache.prefix', 'manual'),
                $this->configHash($app->make(ContentTree::class)),
            );
        });

        $this->app->singleton(ManualSearch::class, function (Application $app): ManualSearch {
            $store = config('manual.cache.store');
            $minLength = config('manual.search.min_term_length', 3);
            $limit = config('manual.search.limit', 10);

            return new ManualSearch(
                $app->make(ContentTree::class),
                $app->make(ManualRepository::class),
                $app->make(ManualRenderer::class),
                Cache::store(is_string($store) ? $store : null),
                $this->stemmers(),
                is_numeric($minLength) ? max(1, (int) $minLength) : 3,
                is_numeric($limit) ? (int) $limit : 10,
            );
        });
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

    /**
     * Hash of every config input that changes rendered output, so a config-only
     * change (callouts, locales, fallback, links) never serves stale cache.
     */
    private function configHash(ContentTree $tree): string
    {
        $configured = config('manual.links');
        $stat = '';

        if (is_string($configured) && $configured !== '' && is_file($configured) && ! str_starts_with($configured, $tree->path().'/')) {
            $stat = filemtime($configured).'|'.filesize($configured);
        }

        return sha1((string) json_encode([
            self::stringList(config('manual.locales')),
            self::stringConfig('manual.source_locale', 'en'),
            self::stringList(config('manual.fallback')),
            self::stringList(config('manual.callouts')),
            $this->links($tree),
            $stat,
        ]));
    }

    /**
     * Locale => stemmer from `manual.stemmers`; an unknown or invalid class is skipped,
     * which means no stemming for that locale.
     *
     * @return array<string, Stemmer>
     */
    private function stemmers(): array
    {
        $configured = config('manual.stemmers');
        $stemmers = [];

        if (! is_array($configured)) {
            return $stemmers;
        }

        foreach ($configured as $locale => $class) {
            if (is_string($class) && is_a($class, Stemmer::class, true)) {
                $stemmers[(string) $locale] = new $class;
            }
        }

        return $stemmers;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function links(ContentTree $tree): array
    {
        $configured = config('manual.links');

        if (is_array($configured)) {
            $links = $configured;
        } else {
            $file = is_string($configured) && $configured !== '' ? $configured : $tree->path().'/_links.php';
            $links = is_file($file) ? (static fn (): mixed => require $file)() : [];
        }

        $result = [];

        if (! is_array($links)) {
            return $result;
        }

        foreach ($links as $key => $urls) {
            if (! is_array($urls)) {
                continue;
            }

            foreach ($urls as $locale => $url) {
                if (is_string($url)) {
                    $result[(string) $key][(string) $locale] = $url;
                }
            }
        }

        return $result;
    }

    private static function stringConfig(string $key, string $default = ''): string
    {
        $value = config($key);

        return is_string($value) ? $value : $default;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
