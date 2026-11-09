<?php

declare(strict_types=1);

namespace IntrAgile\Manual\Facades;

use Illuminate\Support\Facades\Facade;
use IntrAgile\Manual\Content\ManualRepository;

/**
 * @phpstan-import-type TreeSection from ManualRepository
 * @phpstan-import-type Chapter from ManualRepository
 *
 * @method static list<TreeSection> tree(string $locale)
 * @method static Chapter|null chapter(string $slug, string $locale)
 * @method static array{markdown: string, locale: string}|null markdown(string $slug, string $locale)
 *
 * @see ManualRepository
 */
final class Manual extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ManualRepository::class;
    }

    /**
     * Registers the manual routes inside a host-defined route group.
     */
    public static function routes(): void
    {
        // TODO(Task 5): register the manual routes.
    }
}
