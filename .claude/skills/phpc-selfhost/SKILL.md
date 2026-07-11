---
name: phpc-selfhost
description: Drive the self-host generation ladder — the compiler compiling itself (gen-0 → gen-1 → gen-2 → gen-3), verifying fixpoints, and refreshing committed gen-0 sidecars. Use for any bootstrap/spine/gen-0 work, "compile the compiler" requests, or when a sidecar/spine sync gate is red.
---

# php-compiler self-host generation ladder

Canonical reference: `docs/bootstrap-generations.md` (verify counts there — spine size grows weekly; do not hardcode). North star: #1492.

## The ladder in one table

| Gen | Built by | Proves |
|-----|----------|--------|
| **0** | Committed `prelinked/bootstrap-gen0/` driver (or Zend `bin/compile.php` fallback) | The bootstrap seed works without building anything |
| **1** | Gen-0 emit helper | Native compile driver links (`build/bootstrap-loop-gen1-compile`) |
| **2** | Gen-1 | Gen-1 **natively emits** the full spine bundle — the compiler compiled the compiler |
| **3** | Gen-2 argv driver (`-o OUT SOURCE.php`) | Gen-2's output can itself compile the spine — fixpoint reached |

## Daily commands (Docker, LLVM 9)

```bash
# Fast health of the whole self-host stack (~2–5 min; run daily / after lib edits):
make north-star5-verify-fast

# ~20 ms probe after spine edits (no relink):
make bootstrap-selfhost-vm-driver-execute-probe

# Full gen-1→gen-2→gen-3 loop (M4; minutes):
make bootstrap-loop-probe          # or --dry-run first

# Inventory sanity (seconds):
php script/bootstrap-inventory.php --check
```

Pre-merge for bootstrap/gen-0/vendor-prelink changes: `make north-star5-verify ARGS=--strict` (~1 h) — never skip for spine or gen-0 commits. `BOOTSTRAP_M5_NO_ZEND=1` forbids Zend fallbacks when you must prove native-only.

## When the sidecar sync gate is red

`check-selfhost-spine-sidecar-sync: FAILED — stamp X ≠ spine entry SHA-1 Y` means someone edited `test/selfhost/compiler_lib_spine_smoke/main.php` (the spine SSOT) without refreshing committed gen-0 artifacts:

```bash
make bootstrap-gen0-refresh-sidecar   # full spine link; ~10–40 min (warm/cold build/)
git add prelinked/bootstrap-gen0/     # stamp + manifest (+ blobs when changed)
```

Master moves fast — re-check `php script/check-selfhost-spine-sidecar-sync.php` right before committing; the SSOT may have moved again while you linked.

## spine-sync.sh — the one-command chain

`./script/spine-sync.sh` does discovery → bundle inserts → inventory/profile regen → footnote rewrite (6 docs + bundle-test assertion + deferred-ratio comment) → sync checks → sidecar refresh. Flags:

- `--no-link` — everything except the sidecar relink (stamp-only / doc PRs)
- `--footnotes-only` — recount + rewrite pairs only (what ci-fast auto-heal runs)

Discovery is **deferred-SSOT-aware** (won't re-add deferred paths). After ANY merge that unioned spine edits from two branches, check for duplicate requires: `grep -oE "require_once.*';" test/selfhost/compiler_lib_spine_smoke/main.php | sort | uniq -d` — duplicates skew the M2 ratio and every footnote downstream (the count-sync gate now catches this).

## Deferring a file the AOT lowering cannot compile yet

When one inventory file breaks the native spine emit (e.g. #16866's huge array literal), defer it honestly instead of blocking every sidecar refresh:

1. Add the repo-relative path to `bootstrap_spine_native_link_deferred()` in `script/bootstrap-spine-deferred-lib.php` with the tracking-issue reference.
2. Remove its `require_once` from the spine bundle.
3. `./script/spine-sync.sh --no-link` — footnotes become `N-1/N`; then add the `(1 deferred: #NNNN)` annotation to the SIX tracked docs, name the path in at least one doc (`docs/bootstrap-selfhost.md`), and keep the `// Spine ratio N-1/N — 1 deferred (...)` comment next to the bundle-test assertion (spine-sync keeps the numbers fresh).
4. Sidecar refresh + commit everything together. Un-defer when the lowering issue lands.

VM coverage is unaffected — deferral only skips the native-link smoke.

## Critical semantics

- **Gen-0 blobs embed the compiler at the commit that built them.** After fixing a codegen bug in `lib/`/`ext/`, gen binaries keep emitting the OLD lowering until the sidecar is refreshed — behavioral staleness the fast gate does NOT catch (only `--strict` `bootstrap-loop-probe` does).
- New files on the `bin/vm.php` require path must land in the Phase A inventory AND the spine — run `./script/spine-sync.sh`, don't hand-edit.
- Driver preference: `build/bin-compile-aot-inventory` (argv) > `build/bin-compile-aot` > Zend fallback (logged `(gen-0 Zend)`; forbidden under `BOOTSTRAP_M5_NO_ZEND=1`).
- At fleet velocity master grows the inventory every ~20–30 min: sync locally in the same worktree you run gates in and push+merge in one sequence, or you lose the race via the GitHub round-trip.
