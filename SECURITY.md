# Security Policy

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

## Reporting a vulnerability

Please report security issues privately to **info@intragile.eu** rather than opening a public
issue. Include the affected version and, if possible, steps to reproduce. You will receive a
reply within a few working days.

## Scope

The package reads your application's Markdown manual files (and their translations) and serves
them, with server-side search, over a JSON API. It stores nothing except the parsed manual in the
Laravel cache store you configure.

- **Rendering.** Markdown is converted with raw HTML stripped and unsafe link schemes removed.
  Treat any report of script injection through the `html` fields as a vulnerability.
- **Files.** The locale in a request only ever reaches a file name after it has matched the
  `manual.locales` allow-list. The manual path comes from configuration, never from the request.
- **Search.** Search runs on the server; the full manual content is never sent to the client,
  only matching chapters with an excerpt.
- **Access.** The API is private by default (`authenticated`). The package does not authenticate
  users itself: it checks the user your application's guards have already resolved. See the
  access section of the README, in particular the note on `routes.middleware`.
- **Content.** A manual served with `access=public` is public. Do not put internal details in
  a file you intend to expose.

---

Developed and maintained by [IntrAgile Kft.](https://intragile.eu).
