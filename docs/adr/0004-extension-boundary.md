# ADR: Extension boundary — core mandatory, rest side-loaded (#36402)

- Status: Accepted
- Date: 2026-09-04
- Parent: #36402 · related #23480 / #36204 · `.cursor/rules/extensions-sideloaded.mdc`

## Decision

- **Always linked:** compiler core + `ext/standard` (and the thin runtime floor).
- **Side-loaded** via per-extension `ext/<name>/ext.json` manifests (#36204):
  declared deps, default-enabled flag, no `lib/` → `ext/` imports that pull the
  world into every binary.
- Do **not** add entries to `Runtime::loadCoreModules()` for convenience builtins.

## Why

- Extensions are ~72% of the tree; linking all 75 into `echo "hello"` yields
  ~14.8 MB binaries. php-src’s shared-ext model is the user expectation.

## Consequences

- New extensions ship `ext.json` + registry sync scripts; docs/extensions.md is
  generated.
- v2.0 “supported” vs “experimental” lists live in [0012](0012-supported-extension-set-v2.md).
