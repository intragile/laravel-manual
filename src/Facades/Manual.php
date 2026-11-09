<?php

declare(strict_types=1);

namespace IntrAgile\Manual\Facades;

use Illuminate\Support\Facades\Facade;
use IntrAgile\Manual\Content\ManualRepository;

/**
 * @method static list<array{id: string, icon: string, title: string, chapters: list<array{slug: string, number: string, title: string, summary: string}>}> tree(string $locale)
 * @method static array<string, mixed>|null chapter(string $slug, string $locale)
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
