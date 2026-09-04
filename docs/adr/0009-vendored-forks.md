# ADR: Vendored forks over overlay patches (#36402)

- Status: Accepted
- Date: 2026-09-04
- Parent: #36402 · related #36229 / #36377 / #36143

## Decision

- **php-cfg / php-types:** prefer **vendored forks** (committed sources under
  prelinked vendor trees) as the source of truth; stop growing
  `script/apply-patches.sh` overlays for those packages.
- **php-llvm:** keep **patches/** with pristine snapshots +
  `--verify-pristine` stack apply (#36229 / #36143). Idempotent skips required;
  hunkless stub patches are forbidden.

## Why

- `apply-patches.sh` grew larger than the patch corpus; 50%+ of patches neither
  applied nor reversed cleanly (#36229). Clean checkouts and already-patched
  trees failed in different ways (#36377).

## Consequences

- New php-llvm fixes: refresh the patch against pristine snapshots, add skip
  guard, run `script/apply-patches.sh --verify-pristine`.
- Do not edit `vendor/` directly — `patches/` + `script/apply-patches.sh`.
