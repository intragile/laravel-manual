<?php

declare(strict_types=1);

use IntrAgile\Manual\Search\EnglishStemmer;
use IntrAgile\Manual\Search\HungarianStemmer;

return [

    /*
    |--------------------------------------------------------------------------
    | Manual directory
    |--------------------------------------------------------------------------
    |
    | The directory holding the Markdown manual, one sub-directory per locale.
    |
    */

    'path' => resource_path('manual'),

    'locales' => ['en'],

    'source_locale' => 'en',

    // Content fallback chain: requested locale, then these, then `source_locale`.
    'fallback' => ['en'],

    /*
    |--------------------------------------------------------------------------
    | Access
    |--------------------------------------------------------------------------
    |
    | public | authenticated | disabled | any other value is a gate name.
    |
    */

    'access' => 'authenticated',

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    */

    'routes' => [
        'enabled' => true,
        'prefix' => 'manual',
        'middleware' => ['api'],
        'name' => 'manual.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Content
    |--------------------------------------------------------------------------
    */

    'callouts' => ['tip', 'warning', 'note', 'steps'],

    // null = `{path}/_links.php`, when it exists.
    'links' => null,

    // Route names `[[route:name|...]]` may use, checked by `manual:check`; null skips the check.
    // null | list<string> | callable(): iterable<string>, e.g. 'App\Support\Routes::names'
    // (a string callable keeps `config:cache` working; a Closure does not).
    'known_routes' => null,

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    */

    'stemmers' => [
        'hu' => HungarianStemmer::class,
        'en' => EnglishStemmer::class,
    ],

    'search' => [
        'min_term_length' => 3,
        'limit' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | A null store uses the application's default cache store.
    |
    */

    'cache' => [
        'store' => null,
        'prefix' => 'manual',
    ],

];
