# Laravel Manual

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

[![Tests](https://github.com/intragile/laravel-manual/actions/workflows/tests.yml/badge.svg)](https://github.com/intragile/laravel-manual/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/intragile/laravel-manual.svg)](https://packagist.org/packages/intragile/laravel-manual)
[![PHP](https://img.shields.io/packagist/php-v/intragile/laravel-manual.svg)](https://packagist.org/packages/intragile/laravel-manual)
[![License](https://img.shields.io/packagist/l/intragile/laravel-manual.svg)](LICENSE)

A **Markdown user manual that lives in your repository**, rendered and searched on the server and
served over a **JSON API with configurable access**. A console command checks the manual in CI.

[Magyar nyelvű leírás → README.hu.md](README.hu.md)

- **Headless.** The package returns data; your SPA or Blade view draws the help page.
- **Manual as code.** Chapters are Markdown files in your repo, reviewed in the same pull request
  as the feature. No database, no CMS.
- **Translations without a second system.** One directory per language. A chapter missing from
  a translation is served in the next language of the fallback chain and says so.
- **Server-side search.** Per-language stemming, weighted ranking, plain-text snippets. The full
  content never leaves the server.
- **In-manual markup.** Links to other chapters, to routes of your app and to external URLs by key,
  icons, and callout containers (`tip`, `warning`, ...), all resolved on the server.
- **Safe HTML.** Raw HTML is stripped and unsafe links are dropped, so `html` is meant for `v-html`.
- **Access you choose.** Public, signed-in users only, a gate, or off.
- **A broken manual does not ship.** `manual:check` reports a missing translation, a diverging
  structure, a broken link or raw HTML in CI.

## Installation

```bash
composer require intragile/laravel-manual
```

Requires PHP 8.3+ and Laravel 12 or 13. The service provider is discovered automatically.
Publish the config only if you need to change it:

```bash
php artisan vendor:publish --tag=manual-config
```

## Quick start

Read the access section first. With the shipped defaults (`access` is `authenticated`, and the
automatic routes sit in the `api` group only) a request passes only if your **default guard**
already recognises the user. For a typical token API (Sanctum) it does not, so the endpoints
answer **401** even to signed-in users.

Recommended: turn the automatic routes off and register the routes inside the route group that
already authenticates your users. Publish the config first:

```bash
php artisan vendor:publish --tag=manual-config
```

Then, in the published file, switch the automatic routes off and set the languages. `locales` lists
every language the API may serve, and `source_locale` is the language you write in first:

```php
// config/manual.php
'path' => resource_path('manual'),
'locales' => ['hu', 'en'],
'source_locale' => 'hu',
'fallback' => ['en'],

'routes' => [
    'enabled' => false,
    'prefix' => 'manual',
    'middleware' => ['api'],
    'name' => 'manual.',
],
```

```php
// routes/api.php
use IntrAgile\Manual\Facades\Manual;

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Manual::routes();   // GET /api/v1/manual, /manual/chapter, /manual/search
});
```

`Manual::routes()` adds its own `prefix` (`manual`) and route-name prefix (`manual.`) from the
config, so the group above only supplies the outer prefix and the authentication. It does **not**
apply `routes.middleware`; that array belongs to the automatic route group only.

Keep the whole `routes` array in your published config. Laravel merges package config only at the
top level, so a `routes` array you write replaces the shipped one; keys you leave out fall back to
the defaults in the table below.

Then write the first chapter (see the next section) and check it:

```bash
php artisan manual:check
```

## Writing the manual

```
resources/manual/
  _meta.php            the tree: sections, their order and chapters (language independent)
  _links.php           optional: [[link:key]] targets (see below)
  hu/
    _sections.php      section titles in this language
    getting-started/
      intro.md
      first-steps.md
  en/
    _sections.php
    getting-started/
      intro.md
      first-steps.md
```

**`_meta.php`** returns the tree. A section id is a key; `icon` is a free string that is passed to
the client as it is, `order` sorts the sections, and `chapters` lists chapter slugs in reading
order. A chapter is addressed as `{section}/{chapter}`, here `getting-started/intro`.

```php
// resources/manual/_meta.php
return [
    'getting-started' => ['icon' => 'rocket', 'order' => 1, 'chapters' => ['intro', 'first-steps']],
    'admin' => ['icon' => 'settings', 'order' => 2, 'chapters' => ['users']],
];
```

**`{locale}/_sections.php`** maps each section id to its title in that language:

```php
// resources/manual/hu/_sections.php
return ['getting-started' => 'Első lépések', 'admin' => 'Adminisztráció'];
```

**Numbering** comes from the position in `_meta.php`: the second chapter of the first section is
`1.2`. Because the numbers do not depend on the language, **every chapter needs a file in the
source locale**; otherwise numbering would shift between languages. `manual:check` enforces it.

**Section ids and chapter slugs** must match `[A-Za-z0-9_-]+`.

### Chapter files

A chapter is `{path}/{locale}/{section}/{chapter}.md`.

- The first `# Title` is the chapter title. Use the ATX form (`# Title`): a setext title
  (`Title` over `=====`) is rejected by `manual:check`, and the renderer does not read it. A
  chapter has exactly one H1.
- The first paragraph after the title is the **summary**, shown in the tree and in search.
- Use `##` and `###` for sections. They get an `id` and appear in `headings`. `####` and deeper are
  rejected by `manual:check`.
- Tables, lists, quotes and fenced code blocks are standard CommonMark (GFM tables included).
- **Only fenced code blocks count as code.** `manual:check` does not recognise a 4-space indented
  code block: its lines are checked as prose. Always fence code with three backticks or `~~~`.
- Raw HTML is not allowed (the renderer strips it, `manual:check` reports it). Put `List<String>`
  and similar text in backticks.

### Callouts

```markdown
:::tip

Save often.

:::
```

Allowed types are the `callouts` config (`tip`, `warning`, `note` and `steps` by default). Rules:

- `:::type` and the closing `:::` go at **column 0**, each on its own line with a blank line
  before and after. They cannot sit inside a list or a quote; `manual:check` reports that.
- Callouts can nest. An unknown type, or an unclosed one, is an error in `manual:check`.

They render as `<div class="manual-callout" data-type="tip">...</div>`.

### Tokens

| Token | Result |
|---|---|
| `[[manual:section/chapter]]`, optionally `[[manual:section/chapter\|label]]` | `<a class="manual-link" data-slug="section/chapter">`, a link to another chapter |
| `[[route:name]]`, optionally `[[route:name\|label]]` | `<a class="manual-route" data-route="name">`, a link to a screen of your app |
| `[[link:key]]`, optionally `[[link:key\|label]]` | `<a class="manual-external" href="..." target="_blank" rel="noopener noreferrer">` |
| `[[icon:name]]` | `<i class="manual-icon" data-icon="name" aria-hidden="true"></i>` (no label) |

Without a label, the name is shown. The `\|` in the table is how a pipe is written inside a
Markdown table cell; the same rule applies to your chapters:

- Names are limited to `[A-Za-z0-9._/-]`.
- **A literal `|` inside a table cell needs `\|`, including inside a token.**
  `[[manual:a/b\|label]]` in a cell is a token with the label `label`.
- **A token label cannot contain inline markup** (`**bold**`, `` `code` ``, ...); such a token stays
  literal text. Keep labels plain.
- Tokens are resolved only in text, never in code spans or code blocks. To **show a token
  verbatim**, wrap it in a code span: `` `[[manual:a/b]]` ``.
- `manual:check` reports a malformed token, an unknown kind, a `manual` target that is not in
  `_meta.php`, an unknown `link` key and, when `known_routes` is set, an unknown route.

**External links** come from `_links.php` (or the `links` config: an array, or a path to such a
file). The shape is `key => locale => URL`:

```php
// resources/manual/_links.php
return [
    'status' => ['hu' => 'https://example.com/hu/status', 'en' => 'https://example.com/en/status'],
];
```

The URL is picked for the chapter's locale, then `en`, then the first one. Only `http`, `https`
and `mailto` URLs are linked; anything else, and an unknown key, renders as the plain label.

## Configuration

| Key | Default | Meaning |
|---|---|---|
| `path` | `resource_path('manual')` | The manual directory. |
| `locales` | `['en']` | The locales the API accepts. List every translation. The source locale is always allowed as well. |
| `source_locale` | `en` | The language every chapter must exist in; the last step of the fallback chain. |
| `fallback` | `['en']` | Extra languages to try, in order, when the requested one lacks a chapter. |
| `access` | `authenticated` | `public`, `authenticated`, a gate name or `disabled`. See below. |
| `routes.enabled` | `true` | Register the routes automatically. Turn off to call `Manual::routes()` yourself. |
| `routes.prefix` | `manual` | URL prefix. |
| `routes.middleware` | `['api']` | Middleware of the automatic route group. |
| `routes.name` | `manual.` | Route name prefix (`manual.index`, `manual.chapter`, `manual.search`). |
| `callouts` | `['tip', 'warning', 'note', 'steps']` | Allowed `:::type` container names. |
| `links` | `null` | `[[link:]]` map: an array, a file path, or `null` for `{path}/_links.php` if it exists. |
| `known_routes` | `null` | Route names `[[route:]]` may use, checked by `manual:check`. `null` skips the check. A list, or a callable returning an iterable, e.g. `'App\Support\Routes::names'`. Use a string callable, not a Closure, so `config:cache` keeps working. |
| `stemmers` | Hungarian for `hu`, English for `en` | Locale => class implementing `Stemmer`. See Search. |
| `search.min_term_length` | `3` | Shorter query terms are ignored. |
| `search.limit` | `10` | Default number of hits. |
| `cache.store` | `null` | Cache store; `null` uses the default store. |
| `cache.prefix` | `manual` | Cache key prefix. |

## Access

`access` decides who may call the API. The check runs in a middleware that is part of every
package route, so it applies both to the automatic routes and to `Manual::routes()`.

| Value | Behaviour |
|---|---|
| `public` | Anyone. |
| `authenticated` | A user must be signed in; otherwise **401**. |
| a gate name | `Gate::allows(name)` decides. A denied guest gets **401**, a denied signed-in user **403**. |
| `disabled` | **404** for every route. |

A gate cannot be named `public`, `authenticated` or `disabled`: those words always mean the
behaviour in the table.

`authenticated` checks `$request->user()` on the **default guard**. The package adds no
authentication of its own, and the default route group is only `['api']`, so out of the box a
Sanctum token or a session user is not recognised and gets 401. Either:

1. **Recommended:** `routes.enabled = false` and call `Manual::routes()` inside your own
   authenticated group, as in the quick start; or
2. keep the automatic routes and add your authentication middleware:

   ```php
   'routes' => [
       'enabled' => true,
       'prefix' => 'manual',
       'middleware' => ['api', 'auth:sanctum'],
       'name' => 'manual.',
   ],
   ```

If your Sanctum guard is not the default guard, a gate or a custom group is the safer choice.
Use `public` only for content you would also put on a public web page.

Middleware of the surrounding group (such as `auth:sanctum`) runs **before** the package's own
middleware, including the one that forces `Accept: application/json`. A guest who sends no
`Accept` header is therefore rejected by your application's own unauthenticated handling, which by
default redirects to a login route. Make your application render JSON for its API, for example
with `$exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*'))` in
`bootstrap/app.php`.

A gate is consulted for guests too, but Laravel only calls a gate callback for a guest if its first
parameter, the user, is nullable or defaults to `null`. To let guests through a gate, declare it
that way:

```php
Gate::define('view-manual', fn (?User $user): bool => $user === null || $user->can_see_help);
```

A callback that requires a `User`, or takes no parameter at all, denies every guest with 401.

## API

All responses are JSON. The package forces `Accept: application/json` on its routes and throws
ordinary exceptions (`AuthenticationException`, `ValidationException`, `NotFoundHttpException`),
so **your application's exception handler formats the errors**, for example as
`application/problem+json`.

| Endpoint | Description |
|---|---|
| `GET {prefix}` | The tree: sections with their chapters (slug, number, title, summary). |
| `GET {prefix}/chapter?slug=section/chapter` | One rendered chapter. 404 if the slug is unknown or has no file in any language of the chain; 422 if `slug` is missing or longer than 200 characters. |
| `GET {prefix}/search?q=...` | Search hits. 422 if `q` is missing or longer than 200 characters. |

Every endpoint also takes `?locale=` (a string of up to 20 characters).

```json
{
  "data": [
    {
      "id": "getting-started",
      "icon": "rocket",
      "title": "Első lépések",
      "chapters": [
        { "slug": "getting-started/intro", "number": "1.1", "title": "Bevezetés", "summary": "Mi ez, és kinek szól." }
      ]
    }
  ],
  "locale": "hu",
  "fallback": false
}
```

```json
{
  "data": {
    "slug": "getting-started/intro",
    "number": "1.1",
    "title": "Bevezetés",
    "summary": "Mi ez, és kinek szól.",
    "section": "getting-started",
    "position": 1,
    "total": 3,
    "readingMinutes": 2,
    "html": "<p>...</p>",
    "headings": [ { "anchor": "elso-lepesek", "text": "Első lépések", "level": 2 } ],
    "prev": null,
    "next": { "slug": "getting-started/first-steps", "title": "Első lépések" },
    "locale": "hu",
    "fallback": false
  }
}
```

```json
{
  "data": [
    { "slug": "admin/users", "section": "admin", "title": "Felhasználók", "snippet": "... plain-text excerpt ...", "score": 12 }
  ],
  "locale": "hu",
  "fallback": false
}
```

- `number` is a string (`"2.1"`); `prev` and `next` are `{slug, title}` or `null`. A neighbour whose
  chapter has no file in the chain is `null`, not skipped.
- A chapter that has no file in any language of the chain is left out of the tree, and `number`,
  `position`, `total`, `prev` and `next` only count chapters that exist for the language.
- The chapter's `locale` is the language of the file actually read, and `fallback` is `true` when it
  differs from the requested language. The chapter response has no top level `locale`.
- The top level `locale` of the tree and of search is the language the request resolved to: the
  requested language when it is configured, otherwise `source_locale`. Their `fallback` is `true`
  when the request could not be honoured in its own language. It is **language-level**: a single
  chapter that is missing from a configured translation and shown from the fallback does not turn
  the tree's or the search's `fallback` on. Read the chapter's own `fallback` for that.
- `html` is sanitized (see Security) and meant to be inserted with `v-html`.

### Language resolution

The language is resolved from the request in this order:

1. `?locale=`;
2. each `Accept-Language` tag, in the client's quality order. A tag with `q=0` ("not acceptable")
   is ignored. Each tag is tried by its full tag first, then by its primary tag: `pt-BR` matches a
   configured `pt-BR` (or `pt_BR`), otherwise `pt`; `hu-HU` matches `hu`;
3. the application locale (`app()->getLocale()`), **only when the client sent no usable language
   at all**;
4. `source_locale`.

The first candidate from steps 1 and 2 that is in `locales` or is the `source_locale` wins, so
`Accept-Language: de, en;q=0.9` serves `en` when `de` is not configured. If the client asked for
languages but none of them is configured (say only `?locale=de`), the manual is served from the
fallback chain and the tree and search report `fallback: true`: the client learns that its own
language is not available. A malformed value (`?locale=../x`) is ignored.

### Fallback chain

For a requested language, a chapter is looked up in: the requested language, then the languages in
`fallback` (only those that are configured), then `source_locale`. The first file found is served.
The config `fallback` is the **chain** (which languages to try); the `fallback` flag in a response
only says that the language served is not the language asked for.

## Checking the manual

```bash
php artisan manual:check
php artisan manual:check --locale=en     # the source locale plus this one; repeatable
```

It checks every configured language (the source locale plus `locales`) and reports, with the
chapter and language:

- `_meta.php`: missing, not loadable, malformed sections or chapters, an invalid id or slug,
  duplicate chapters, a manual with no chapters;
- `_sections.php`: missing section titles, unknown section keys;
- files: a Markdown file that is not listed in `_meta.php` (an orphan), a chapter missing in the
  source locale, a chapter missing in a translation;
- structure: no title, more than one H1, a setext title, no summary, `####` or deeper headings,
  unknown, unbalanced, indented or quoted `:::` markers, `:::` markers without blank lines;
- content: raw HTML, malformed or unresolved tokens, a broken `manual` link, an unknown `link` key,
  an unknown route (only with `known_routes`), a link URL that would not be linked;
- **parity**: a translation whose block structure (headings, lists, tables, callouts, code blocks,
  paragraphs) differs from the source locale. A translated code comment or a different line wrap
  is not a difference; a missing paragraph or section is.

With any error the command prints each message and exits with a non-zero status, so it can gate a
pull request:

```yaml
# .github/workflows/tests.yml
- name: Check the manual
  run: php artisan manual:check
```

`--locale` with a language that is not in `locales` is an error. Fenced code blocks are ignored by
every line rule, and the raw HTML and token checks also ignore inline code spans.

`manual:clear` invalidates the cache (see below).

## Search

The search runs on the server and builds its index lazily. The query (at most 200 characters) is
split into words, accents are folded (`ertesites` finds `Értesítési`), and each word is cut to a
stem by the stemmer of the **resolved** language. A chapter matches when **every** word occurs in it
as a substring. Hits are ranked by weighted occurrences: title 6, headings 3, summary 2, body 1.
Words shorter than `search.min_term_length` are ignored.

Built in: `HungarianStemmer` and `EnglishStemmer`, light suffix trimmers that keep at least four
characters. A language without a stemmer is searched as typed. Stemming is applied to the
query only; the content is searched as written.

Fallback content is searched with the **requested** language's stemmer: a Hungarian chapter shown
under an English request is searched with the English stemmer.

To register your own, implement `IntrAgile\Manual\Search\Stemmer` and list it by locale. The class
needs a constructor without arguments, and receives a lowercase ASCII word (accents already folded):

```php
namespace App\Search;

use IntrAgile\Manual\Search\Stemmer;

final class GermanStemmer implements Stemmer
{
    public function stem(string $normalizedWord): string
    {
        return preg_replace('/(en|er|e|n|s)$/', '', $normalizedWord) ?? $normalizedWord;
    }
}
```

```php
// config/manual.php
'stemmers' => [
    'hu' => \IntrAgile\Manual\Search\HungarianStemmer::class,
    'en' => \IntrAgile\Manual\Search\EnglishStemmer::class,
    'de' => \App\Search\GermanStemmer::class,
],
```

An entry whose class does not exist or does not implement `Stemmer` is skipped, which means no
stemming for that language.

The `snippet` of a hit is **plain text** cut from the chapter around the first match. It is not
sanitized HTML: render it as text (`{{ hit.snippet }}`), not with `v-html`, or escape it.

## Programmatic use

```php
use IntrAgile\Manual\Facades\Manual;

Manual::tree('hu');                    // list of sections with chapters
Manual::chapter('admin/users', 'hu');  // array or null
Manual::markdown('admin/users', 'hu'); // ['markdown' => ..., 'locale' => ...] or null (uncached)
Manual::search('számla', 'hu', 5);     // list of hits, optional limit
```

`Manual::spy()` and `Manual::swap()` work, because the facade root is the `ManualManager` service.
The return shapes are the JSON shapes above (without the `data` wrapper). A `slug` that is not in
`_meta.php` gives `null`. Pass the locale you want; unlike the HTTP layer, the facade does not read
the request.

## Frontend tips

- The `html` of a chapter is safe to insert with `v-html`. Handle clicks with **one delegated
  listener** on the container instead of binding links: look for the closest `[data-slug]` and
  open that chapter, or the closest `[data-route]` and push that route name in your router. The
  links have `tabindex="0"` and `role="link"`, so also handle Enter.
- `[[icon:name]]` becomes `<i class="manual-icon" data-icon="name">`. Map `data-icon` to your icon
  set (CSS, or replace the element); the `icon` of a section in the tree is the same kind of free
  string.
- Style callouts with `.manual-callout[data-type="tip"]` (and `warning`, `note`, `steps`, or
  whatever you configured). Tables are wrapped in `.manual-table-wrap` so they can scroll.
- `h2` and `h3` carry an `id` equal to the `anchor` in `headings`, so you can build a table of
  contents and scroll to `#anchor`.
- Send `?locale=` or `Accept-Language` from your SPA, which usually keeps the user's language
  client-side. Read `fallback` on a chapter to show a "not translated yet" note.
- Render a search `snippet` as text, never as HTML.

## Public API and semantic versioning

The package follows [Semantic Versioning](https://semver.org/). From 1.0.0 on, a breaking change to
any of the following waits for the next major version:

- the `Manual` facade methods: `tree()`, `chapter()`, `markdown()`, `search()` and `routes()`;
- the `IntrAgile\Manual\Search\Stemmer` interface (so your own stemmers keep working);
- the config keys and their meaning;
- the routes, their names and query parameters, and the JSON shapes documented above;
- the content format: `_meta.php`, `_sections.php`, `_links.php`, the token and callout syntax and
  the rendered markup (`manual-link`, `manual-route`, `manual-external`, `manual-icon`,
  `manual-callout`, and the `data-slug`, `data-route`, `data-icon`, `data-type` attributes);
- the `manual:check` and `manual:clear` commands, their options and exit status. The wording of
  the individual messages is not covered, and new checks may be added in a minor version.

Not covered: every class or method marked `@internal` (the controller, middleware, resources,
command classes, the locale resolver), and the classes behind the facade: `ManualManager`,
`ManualRepository`, `ContentTree`, `ManualSearch`, `ManualRenderer`, `ManualValidator` and the
built-in stemmers' internals. Use the facade.

## Caching

The tree, each chapter (rendered HTML included) and the search index are cached in the configured
store. Entries hold only arrays and strings, so they work with stores that refuse to unserialize
objects (`cache.serializable_classes = false`). A key is built from:

- `CACHE_VERSION`, a constant of the package, bumped when the stored shape changes;
- a hash of the config that affects output (`locales`, `source_locale`, `fallback`, `callouts` and
  the resolved links);
- a content fingerprint: the path, modification time and size of every `.md` and `.php` file in
  the manual directory;
- the cache **generation**.

A deploy that changes a file or the config therefore shows up without clearing anything. Tree and
chapter entries expire after one day and the search index after a week, so old keys do not pile
up.

`php artisan manual:clear` bumps the generation: every old entry becomes unreachable at once and
simply expires. It works on **any** cache store (no tags, no key listing). Running workers pick
the new generation up on their next request, because the package's bindings are **scoped**: a
fresh instance per request or job, so Octane and queue workers are safe.

Limitation: an edit made within the same second as the previous one that leaves the file size
exactly the same is not detected. Run `manual:clear` in that case. In practice it only matters
when you edit a file in place by hand; deploys replace files.

## Security

Markdown is rendered with raw HTML **stripped** (`html_input: strip`) and unsafe link schemes
such as `javascript:` removed (`allow_unsafe_links: false`), so `html` is safe to insert into a
page. `[[link:]]` URLs must be `http`, `https` or `mailto`. Locales only ever come from the
configured allow-list before they reach a file name, and a chapter slug must be listed in
`_meta.php` before a path is built, so a request cannot make the package read an arbitrary file.
See [SECURITY.md](SECURITY.md). The search `snippet` is plain text: escape it.

## Testing and quality

```bash
composer test
composer analyse
vendor/bin/pint --test
```

`composer analyse` runs PHPStan with `--memory-limit=1G`. In your own CI call PHPStan with a
`--memory-limit` as well (`vendor/bin/phpstan analyse --memory-limit=1G`); at the max level it
can run out of PHP's default memory.

## License

MIT. See [LICENSE](LICENSE).

---

Developed and maintained by [IntrAgile Kft.](https://intragile.eu).
