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

## Critical semantics

- **Gen-0 blobs embed the compiler at the commit that built them.** After fixing a codegen bug in `lib/`/`ext/`, gen binaries keep emitting the OLD lowering until the sidecar is refreshed — behavioral staleness the fast gate does NOT catch (only `--strict` `bootstrap-loop-probe` does).
- New files on the `bin/vm.php` require path must land in the Phase A inventory AND the spine: `php script/bootstrap-inventory.php`, then update spine main.php, then refresh sidecar.
- Driver preference: `build/bin-compile-aot-inventory` (argv) > `build/bin-compile-aot` > Zend fallback (logged `(gen-0 Zend)`; forbidden under `BOOTSTRAP_M5_NO_ZEND=1`).
