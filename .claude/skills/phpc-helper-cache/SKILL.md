---
name: phpc-helper-cache
description: Work with the split-compilation helper-object cache — incremental per-unit emission, the committed per-arch prelinked objects, fingerprints, and how to debug a failing unit. Use for "recompile only changed parts", helper-unit emission failures, prelinked/helper-runtime refreshes, or PHP_COMPILER_HELPER_RUNTIME_O questions.
---

# php-compiler split-compilation helper cache (#15889)

Every `JitVmHelperLink` helper unit (`*HELPER_PATH`/`*COMPILED_HELPERS` constant pairs in lib/ext) is its own translation unit, cached as `{unit.o, unit.bc, manifest.json}`:

- **Local tier**: `build/helper-runtime-cache/units/<slug>/` (gitignored)
- **Committed tier**: `prelinked/helper-runtime/<uname m>-<uname s>/units/` — a fresh clone on the same arch binds these cold

Consumption is **opt-in**: `PHP_COMPILER_HELPER_RUNTIME_O=1`. Local tier outranks committed; stale entries in either tier are skipped per fingerprint and recompiled — a stale cache costs time, never correctness.

## Commands

```bash
php script/emit-helper-runtime-object.php            # incremental sweep (warm no-change ≈ 0.2 s; one edit ≈ 3 s)
php script/emit-helper-runtime-object.php --force    # re-emit everything (~6 min)
php script/emit-helper-runtime-object.php --unit=/ext/standard/FooJitHelper.php   # ONE unit — the 10-second repro harness
make helper-runtime-prelink-refresh                  # publish fresh units into prelinked/<arch>/ (commit when intentional)
make helper-runtime-prelink-check                    # report-only freshness of the committed tier (--strict to gate)
```

## Fingerprints (what invalidates what)

`unitFingerprint = sha256(coreFingerprint + unit source content)`. The core covers `composer.lock`, `lib/JIT.php`, `lib/JIT/Context.php`, `lib/Runtime.php`, `lib/JIT/JitVmHelperLink.php`, `script/apply-patches.sh`, and every `patches/*.patch` — all **content hashes**, so fingerprints agree across clones (that is what makes the committed tier shareable). Editing one helper re-emits one unit; editing the JIT core re-emits all. Crash markers (`failed.json`) are fingerprint-keyed: a broken unit is re-attempted only when its inputs change, and is **never** committed to the prelinked tier.

## Debugging a failing unit

1. Re-run the single unit with `PHP_COMPILER_LLVM_ASSERT=1` — segfault classes become PHP exceptions with backtraces (see phpc-verify).
2. `Call to undefined method PHPCompiler\VM\...` = the **corpus-dependency class**: the helper calls VM-runtime classes (HashTable → Refcount → php\MaskedArray → SplFixedArray → the spine driver's `php\` bundled-external prefix) that only exist in full spine builds. Needs extern class-method binding (epic #16075); the runtime falls back to nested lowering correctly meanwhile. `--preload=<paths>` exists for leaf-first corpus experiments.
3. Type-shaped module-verify failures usually mean a lowering used a raw `Variable->value` where the loaded/boxed form was required.

## Sharp edges

- `unit.bc` is a **declarations-only** module (consumers only read function types from it). When binding, types are re-localized to the consumer context's named structs — LLVM re-suffixes (`__string__.12`) on parse, and suffixed declaration types fail module verify at call sites.
- Unit objects lack their `__init__`/ctor chains — units whose helpers need module-init state crash at runtime in **user** builds until per-unit `llvm.global_ctors` lands (epic #16075 step 4/5). The spine/self-host consumers are unaffected.
- The emitter never consumes the cache itself (`PHP_COMPILER_HELPER_RUNTIME_EMITTING` guard).
- Refreshes rewrite ~30 MB of committed blobs — batch them; don't refresh per-PR.
