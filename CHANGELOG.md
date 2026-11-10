# Changelog

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

All notable changes are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

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

[1.0.0]: https://github.com/intragile/laravel-manual/releases/tag/v1.0.0

---

Developed and maintained by [IntrAgile Kft.](https://intragile.eu).
