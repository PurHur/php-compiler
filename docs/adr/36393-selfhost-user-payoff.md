# ADR: Self-host north star scoped to the user payoff (#36393)

- Status: Accepted
- Date: 2026-09-04
- Parent: #36393 · Wave 2 #36379 · related #36402 · living tracker #1492

## Decision

**Release-scoped M5 is the user payoff, not a full-revision fixpoint.**

1. **Ship criterion:** a static `phpc` binary (no host PHP, no Composer, no vendor
   patches at cold boot) that compiles an arbitrary user project — including a
   Composer app from the real-world corpus (#36380 / #36382) — to a working native
   binary, reproducibly.
2. **Research milestone (not a release gate):** gen-2 == gen-3 byte fixpoint and
   `north-star5-verify --strict` full ladder. Keep the ladder; stop blocking user
   Ship / Trust work on it.
3. **Capacity split:** ≤ ~25 % of fleet lanes on self-host / gen-0 / spine split-TU
   until the first three Wave-2 apps (#36380) and the v2.0 performance targets
   (#36386) are green. Prefer `north-star5-verify-fast` for daily iteration;
   `--strict` only when merging bootstrap/gen-0/vendor-prelink batches.

## Why

- Months of fleet capacity went into spine coverage, gen-0 sidecars, and honesty
  gates while users still cannot install one binary and build a real project.
- The gen-0 provenance problem (#36145 / #23468) and COPY-as-native failures
  (#21860 / #36146) showed that chasing ladder colour without a user-visible
  artifact produces cheap greens.
- Option 2 (keep the current ladder as the north star) and option 3 (pause
  self-host entirely) both lose the one distribution win self-host actually buys:
  dissolving the “clean checkout unbuildable” class (#36377) for end users.

## Consequences

- `docs/self-host-target.md` states this scope; the composite % formula still
  tracks the research ladder, but **release readiness** keys off the user-payoff
  gates (static `phpc` builds corpus app; `north-star5-verify-fast` green; honest
  native emit under `BOOTSTRAP_M4_REQUIRE_NATIVE_EMIT=1`).
- `release.yml` / SDK packaging (#36390) publish the gen-0–built `phpc` once the
  Composer-app smoke is green — not when gen-2 equals gen-3.
- Workers must not restamp gen-0 fingerprints to clear freshness (#36145); rebuild
  or report stale.
- Revisit only if a measured user install path no longer needs a static binary
  (unlikely while host PHP + patches remain a support burden).
