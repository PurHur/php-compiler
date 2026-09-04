# ADR: Gen-0 is a release artifact — rebuild, never restamp (#36402)

- Status: Accepted
- Date: 2026-09-04
- Parent: #36402 · related #36145 / #23468 / #22642 · [36393](36393-selfhost-user-payoff.md)

## Decision

Committed `prelinked/bootstrap-gen0/` (and vendor prelink objects) are **release
artifacts**. Freshness gates may **warn** on fingerprint drift; they must not be
cleared by rewriting stamps without a build receipt.

- Stale → rebuild via `script/bootstrap-gen0-refresh-argv-driver.sh` /
  `script/bootstrap-refresh-gen0-sidecar.sh` (Docker memory caps).
- `BOOTSTRAP_M4_REQUIRE_NATIVE_EMIT=1` refuses sidecar COPY reported as native
  (#36146 / #21860).

## Why

- 272+ manifest restamps left a gen-0 driver that could not compile hello-world
  while every freshness gate was green (#23468).
- Release M5 ships this binary to users ([36393](36393-selfhost-user-payoff.md)).

## Consequences

- Fast path: warn-only provenance (#36145). `--strict` / native bootstrap refuse
  unverified-restamp.
- Workers never “fix” trust by editing `.sha` / fingerprint files alone.
