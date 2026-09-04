# ADR: Supported extension set for v2.0 (#36402)

- Status: Accepted
- Date: 2026-09-04
- Parent: #36402 · related #36204 / [0004](0004-extension-boundary.md)

## Decision

**v2.0 supported (non-experimental) extensions** — must keep differential / smoke
signal and appear as supported in generated docs:

`standard`, `spl`, `ctype`, `hash`, `random`, `json`, `mbstring`, `pcre`, `date`,
`session`, `curl`, `pdo` + `pdo_sqlite`, `dom` / `xml` / `xmlreader` / `xmlwriter`,
`openssl`, `sodium`, `zlib`

Everything else under `ext/` is **experimental**: may exist, may be side-loaded,
but is not a release blocker and must not be implied by README “supported”
claims. Explicitly unsupported for v1.1 / v2.0 product claims: **intl**, **gmp**
(and similar leaf math/i18n dirs until a dedicated epic).

## Why

- 84 extension directories cannot all be release surface; concentration matches
  real Composer app needs (#36380) without owning every php-src ext.
- Side-loading (#23480) only helps if the “supported” set is finite and tested.

## Consequences

- `ext.json` `default-enabled` follows this list; experimental defaults off.
- Compliance-baseline churn on intl/gmp is out of scope for release trackers.
- New “supported” promotions need an ADR amendment + corpus coverage.
