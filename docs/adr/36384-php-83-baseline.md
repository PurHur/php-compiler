# ADR: PHP 8.3 language baseline for v2.0 (#36384)

- Status: Accepted
- Date: 2026-09-04
- Parent: #36384 · Wave 2 #36379 · related #36402

## Decision

**Supported language baseline for v2.0 is PHP 8.3 semantics.**

- Default user-facing claim: “PHP 8.3 subset” (see `docs/capabilities-syntax.md`).
- `PHP_COMPILER_PROFILE=8.4` (and later) remains an **opt-in forward profile** for 8.4+ syntax
  (property hooks, asymmetric visibility, …).
- Stop adding **8.2-only rejection** paths for features that exist in 8.3; keep php-src-strict
  parity against the active profile, not against a frozen 8.2 phantom gate forever.
- The pinned Docker / release image may continue to **ship host PHP 8.2.x** as the *compiler
  process* runtime (locked Composer deps); that is independent of the *language* baseline the
  compiled programs target.

## Why

- PHP 8.2 is security-only from Dec 2025; new language work should not pretend 8.2 is the product.
- The compiler already implements large 8.3 surfaces (`#[\Override]`, typed class constants,
  typed function-local statics) behind profile gates.
- Advertising 8.4.0-dev as `CompilerVersion::VERSION` while the reference harness reports 8.2.31
  confused users; the baseline ADR separates **language target** from **host/tooling PHP**.

## Consequences

- README / Getting Started state the 8.3 baseline and point at this ADR.
- Syntax-matrix probes record observed VM/JIT/AOT cells; regenerate via
  `php script/capability-syntax.php --refresh-probes` when lowering drifts
  (`script/capability-syntax-probe-cache.json`).
- Changing `CompilerVersion::VERSION` / default reported `phpversion()` is a follow-up; this ADR
  does not restamp identity strings by itself.
