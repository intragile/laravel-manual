# Changelog

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

All notable changes are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Fixed

- Plain text for search and summaries keeps a hyphen inside a word or number, so "API-ját",
  "e-mail" or "200-299" no longer turn into two words in snippets. List markers, horizontal rules
  and dashes standing on their own are still removed. The cache version moves to 3, so cached
  search indexes and chapters are rebuilt once after the upgrade.

## [1.0.1] - 2026-11-10

### Fixed

- Search snippets start and end on whole words instead of cutting a word in half, and no longer
  begin with the chapter title, which every hit already shows separately. A heading without
  closing punctuation now ends with a period instead of running into the next sentence. The cache
  version moves to 2, so cached search indexes and chapters are rebuilt once after the upgrade.
  Because the title is no longer part of the body text, a word in the title scores through the
  title alone: a chapter that matches only in its title can now rank just below one that matches
  in a heading and the body, where the two used to tie.
- A symbolic link inside the manual directory that points to the directory itself or one of its
  ancestors no longer sends the content fingerprint into an endless loop.
- A chapter summary no longer treats a line such as ```` ```~~~ ```` as the end of a ```` ``` ````
  code block: a fence closes only with the same character, at least as long as the opening one,
  exactly as `manual:check` and CommonMark read it.
- `?locale=` sent empty or blank no longer answers 422 when the application trims strings and
  converts empty ones to null; the locale is then taken from `Accept-Language` as if it were absent.
- The package no longer calls the global helpers that only `laravel/framework` defines (`config()`,
  `config_path()`, `app()`, `abort()`, `abort_if()`, `now()`).

## [1.0.0] - 2026-11-10

### Added

- Markdown manual in your repository: a language-independent tree in `_meta.php`, section titles
  per language in `_sections.php`, one `{locale}/{section}/{chapter}.md` file per chapter, with
  numbering, previous and next chapter, headings with anchors and a reading-time estimate.
- Safe rendering: raw HTML stripped and unsafe links removed, plus the manual's own markup:
  `[[manual:]]`, `[[route:]]`, `[[link:]]` and `[[icon:]]` tokens, and `:::type` callout
  containers with a configurable list of types.
- Translations with a configurable fallback chain (requested language, `fallback`, then
  `source_locale`) and `locale` / `fallback` markers on every chapter and on the tree and search.
- Server-side search with per-locale stemmers (Hungarian and English built in, your own through the
  `Stemmer` interface and the `stemmers` config), weighted ranking and plain-text snippets.
- JSON API: `GET {prefix}` (tree), `GET {prefix}/chapter?slug=` and `GET {prefix}/search?q=`.
- Locale resolution from `?locale=`, then `Accept-Language` (full tag such as `pt-BR` first, then the
  primary tag, `q=0` ignored, an allowed language preferred), then the application locale, limited
  to the configured locales.
- Access control: `public`, `authenticated`, the name of a gate, or `disabled`.
- `Manual::routes()` to register the routes inside the host application's own route group.
- Programmatic access through the `Manual` facade: `tree()`, `chapter()`, `markdown()` and
  `search()`, backed by the `ManualManager` service so `Manual::spy()` and `Manual::swap()` work.
- Caching of arrays only, keyed on the cache version, a config hash, a content fingerprint and a
  generation; `manual:clear` invalidates it on any cache store, and the bindings are scoped so
  Octane and queue workers are safe.
- `manual:check` command: tree and section titles, orphan files, missing translations, chapter
  structure, raw HTML, tokens and links, known routes, and structural parity between translations.
  Exits non-zero on any error, for CI.
- `manual:clear` command.

[Unreleased]: https://github.com/intragile/laravel-manual/compare/v1.0.1...HEAD
[1.0.1]: https://github.com/intragile/laravel-manual/releases/tag/v1.0.1
[1.0.0]: https://github.com/intragile/laravel-manual/releases/tag/v1.0.0

---

Developed and maintained by [IntrAgile Kft.](https://intragile.eu).
