<?php

declare(strict_types=1);

namespace IntrAgile\Manual\Facades;

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Route;
use IntrAgile\Manual\Content\ManualRepository;
use IntrAgile\Manual\Http\Controllers\ManualController;
use IntrAgile\Manual\Http\Middleware\EnsureManualAccess;
use IntrAgile\Manual\Http\Middleware\ForceJsonResponse;
use IntrAgile\Manual\ManualManager;
use IntrAgile\Manual\Search\ManualSearch;

/**
 * @phpstan-import-type TreeSection from ManualRepository
 * @phpstan-import-type Chapter from ManualRepository
 * @phpstan-import-type SearchHit from ManualSearch
 *
 * @method static list<TreeSection> tree(string $locale)
 * @method static Chapter|null chapter(string $slug, string $locale)
 * @method static array{markdown: string, locale: string}|null markdown(string $slug, string $locale)
 * @method static list<SearchHit> search(string $query, string $locale, ?int $limit = null)
 *
 * @see ManualManager
 */
final class Manual extends Facade
{
    /**
     * Registers the manual HTTP routes (`{prefix}`, `{prefix}/chapter`, `{prefix}/search`).
     *
     * The provider calls this inside the configured `routes.middleware`; a host that disables
     * `routes.enabled` calls it inside its own group instead. The access check and JSON
     * responses are always applied here.
     */
    public static function routes(): void
    {
        Route::prefix(self::config('prefix', 'manual'))
            ->middleware([ForceJsonResponse::class, EnsureManualAccess::class])
            ->name(self::config('name', 'manual.'))
            ->group(static function (): void {
                Route::get('/', [ManualController::class, 'index'])->name('index');
                Route::get('/chapter', [ManualController::class, 'chapter'])->name('chapter');
                Route::get('/search', [ManualController::class, 'search'])->name('search');
            });
    }

    private static function config(string $key, string $default): string
    {
        $value = config('manual.routes.'.$key, $default);

        return is_string($value) ? trim($value, $key === 'prefix' ? '/' : '') : $default;
    }

    protected static function getFacadeAccessor(): string
    {
        return ManualManager::class;
    }
}
