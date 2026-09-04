# Architecture decision records

Index of accepted (and deferred) decisions that agents and contributors must follow.
New architectural PRs must **reference an ADR** or add one under `docs/adr/`.

Parent: [#36402](https://github.com/PurHur/php-compiler/issues/36402) · Wave 2 [#36379](https://github.com/PurHur/php-compiler/issues/36379)

## Settled (code already embodies these)

| ADR | Decision |
|-----|----------|
| [0001](0001-value-representation.md) | Boxed `__value__` + native fast paths with guards |
| [0002](0002-memory-model.md) | Refcount + cycle GC; request arena for workers |
| [0003](0003-per-unit-translation-units.md) | Per-unit LLVM objects + helper ABI manifest |
| [0004](0004-extension-boundary.md) | Core + `ext/standard` mandatory; others side-loaded via `ext.json` |
| [0005](0005-differential-oracle.md) | Differential-against-Zend is the primary correctness oracle |
| [0006](0006-generated-docs-only.md) | Generated docs only — hand-edited matrix cells are bugs |
| [0007](0007-gen0-release-artifact.md) | Gen-0 / prelinked `phpc` is a release artifact (rebuild, never restamp) |
| [0008](0008-llvm9-to-22.md) | LLVM 9 now → 22 via pointee threading |
| [0009](0009-vendored-forks.md) | Prefer vendored forks; patches only for php-llvm |
| [0010](0010-fleet-definition-of-done.md) | Fleet Definition of Done — no partial “Closes #N” |

## Maintainer DECISIONs (answered)

| ADR | Decision |
|-----|----------|
| [0011](0011-jit-tier-future.md) | Retire `phpc serve --jit` as a product path until ORC; AOT + VM are the Ship tiers |
| [36384](36384-php-83-baseline.md) | PHP **8.3** language baseline for v2.0 |
| [0012](0012-supported-extension-set-v2.md) | v2.0 supported extension set (rest experimental) |
| [36393](36393-selfhost-user-payoff.md) | Release M5 = static `phpc` builds user projects; fixpoint is research |

## Related / deferred

| ADR | Decision |
|-----|----------|
| [36391](36391-aarch64-darwin-deferred.md) | `aarch64-darwin` data-only until LLVM 22 |

## Gate

`script/lib/check-adr-index.sh` (via `script/check-generated-docs.sh`) fails if this index omits an on-disk `docs/adr/*.md` or if ADRs `0001`–`0012` are missing.
