<?php

declare(strict_types=1);

namespace IntrAgile\Manual;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use IntrAgile\Manual\Console\CheckCommand;
use IntrAgile\Manual\Console\ClearCommand;
use IntrAgile\Manual\Content\ContentTree;
use IntrAgile\Manual\Content\ManualRepository;
use IntrAgile\Manual\Facades\Manual;
use IntrAgile\Manual\Rendering\ManualRenderer;
use IntrAgile\Manual\Search\ManualSearch;
use IntrAgile\Manual\Search\Stemmer;
use IntrAgile\Manual\Validation\ManualValidator;

final class ManualServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/manual.php', 'manual');

        $this->app->singleton(ContentTree::class, fn (): ContentTree => new ContentTree(
            $this->stringConfig('manual.path'),
            self::stringList($this->config()->get('manual.locales')),
            $this->stringConfig('manual.source_locale', 'en'),
            self::stringList($this->config()->get('manual.fallback')),
        ));

        $this->app->singleton(ManualRenderer::class, fn (Application $app): ManualRenderer => new ManualRenderer(
            self::stringList($this->config()->get('manual.callouts')),
            $this->links($app->make(ContentTree::class)->path()),
        ));

        // Scoped, not singleton: the cache generation is read once per instance, and a
        // long-running worker (Octane, queue) must see a `manual:clear` on its next request.
        $this->app->scoped(ManualRepository::class, function (Application $app): ManualRepository {
            $store = $this->config()->get('manual.cache.store');

            return new ManualRepository(
                $app->make(ContentTree::class),
                $app->make(ManualRenderer::class),
                Cache::store(is_string($store) ? $store : null),
                $this->stringConfig('manual.cache.prefix', 'manual'),
                $this->configHash($app->make(ContentTree::class)),
            );
        });

        $this->app->scoped(ManualSearch::class, function (Application $app): ManualSearch {
            $store = $this->config()->get('manual.cache.store');
            $minLength = $this->config()->get('manual.search.min_term_length', 3);
            $limit = $this->config()->get('manual.search.limit', 10);

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

        $this->app->scoped(ManualManager::class);

        // Built from the current config on every resolve, like the command run it serves.
        $this->app->bind(ManualValidator::class, function (): ManualValidator {
            $path = $this->stringConfig('manual.path');
            $links = $this->links($path);
            $callouts = self::stringList($this->config()->get('manual.callouts'));

            return new ManualValidator(
                $path,
                self::stringList($this->config()->get('manual.locales')),
                $this->stringConfig('manual.source_locale', 'en'),
                $callouts,
                $links,
                $this->config()->get('manual.known_routes'),
                new ManualRenderer($callouts, $links),
            );
        });
    }

    public function boot(): void
    {
        // Defaults mirror config/manual.php: a published config that overrides
        // only part of `routes` replaces the whole array (the merge is shallow).
        $enabled = filter_var($this->config()->get('manual.routes.enabled', true), FILTER_VALIDATE_BOOL);

        if ($enabled && ! $this->app->routesAreCached()) {
            $middleware = $this->config()->get('manual.routes.middleware', ['api']);

            Route::middleware(is_array($middleware) ? $middleware : [])
                ->group(static fn () => Manual::routes());
        }

        if ($this->app->runningInConsole()) {
            $this->commands([CheckCommand::class, ClearCommand::class]);

            $this->publishes([
                __DIR__.'/../config/manual.php' => $this->app->configPath('manual.php'),
            ], 'manual-config');
        }
    }

    /**
     * Hash of every config input that changes rendered output, so a config-only
     * change (callouts, locales, fallback, links) never serves stale cache.
     */
    private function configHash(ContentTree $tree): string
    {
        $configured = $this->config()->get('manual.links');
        $stat = '';

        if (is_string($configured) && $configured !== '' && is_file($configured) && ! str_starts_with($configured, $tree->path().'/')) {
            $stat = filemtime($configured).'|'.filesize($configured);
        }

        return sha1((string) json_encode([
            self::stringList($this->config()->get('manual.locales')),
            $this->stringConfig('manual.source_locale', 'en'),
            self::stringList($this->config()->get('manual.fallback')),
            self::stringList($this->config()->get('manual.callouts')),
            $this->links($tree->path()),
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
        $configured = $this->config()->get('manual.stemmers');
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
    private function links(string $path): array
    {
        $configured = $this->config()->get('manual.links');

        if (is_array($configured)) {
            $links = $configured;
        } else {
            $file = is_string($configured) && $configured !== '' ? $configured : $path.'/_links.php';
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

    /**
     * Resolved on every call, like the config() helper, so a test or a host that swaps the
     * repository is always seen.
     */
    private function config(): Config
    {
        return $this->app->make(Config::class);
    }

    private function stringConfig(string $key, string $default = ''): string
    {
        $value = $this->config()->get($key);

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
