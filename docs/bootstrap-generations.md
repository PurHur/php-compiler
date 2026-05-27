# Bootstrap generations — compiler compiles the compiler

**North star:** [#1492](https://github.com/PurHur/php-compiler/issues/1492) · **M4 loop:** [#1498](https://github.com/PurHur/php-compiler/issues/1498) · **Native driver:** [#2697](https://github.com/PurHur/php-compiler/pull/2697)

This document is the **canonical reference** for generation numbering, artifacts, and presenter commands. Use it when updating docs, Makefile targets, or CI sync checks.

---

## Generation ladder (May 2026, verified LLVM 9)

| Gen | Built by | Artifact | What it proves |
|-----|----------|----------|----------------|
| **0** | Zend `php bin/compile.php` | `build/selfhost-helloworld-compile`, emit helpers | M3 emit TU links; Zend still drives the link step (`emit_path=zend partial` when gen-1 native emit blocked) |
| **1** | Gen-0 emit helper | `build/bootstrap-loop-gen1-compile` | Native compile driver (M3 bridge) is linkable |
| **2 (smoke)** | Gen-1 emit helper | `build/bootstrap-loop-gen2` | Gen-1 **native-emits** a smoke fixture (`compiler smoke`) |
| **2 (spine)** | Gen-1 full-spine emit | `build/bootstrap-loop-gen2-full-spine` | Gen-1 **native-emits** the **717/717** spine bundle |
| **3** | Gen-2 native driver | `build/bootstrap-loop-gen3-full-spine` | Gen-2 **recompiles** the **717/717** spine without Zend on the compile step |

**Gen-2 compiles itself** in the M4 sense: `build/bin-compile-aot` (M3 native compile driver, linked via `./script/bootstrap-loop-gen1-link.sh`) reads a source path and emits the next native binary via `PHP_COMPILER_M3_SOURCE` / `PHP_COMPILER_M3_OUT`. Zend **fallback** (`emit_path=zend partial`) remains in gen-1 link when native emit is blocked. The driver is **not** yet a full argv `bin/compile.php -o` CLI for arbitrary PHP ([#1937](https://github.com/PurHur/php-compiler/issues/1937)).

---

## Key artifacts

| Path | Role |
|------|------|
| `build/bin-compile-aot` | Native compile driver (M3 emit helper linked for `bin/compile.php`) |
| `build/bootstrap-loop-gen1` | Gen-1 smoke bundle (runs before emit) |
| `build/bootstrap-loop-gen1-compile` | Gen-1 emit helper |
| `build/bootstrap-loop-gen2` | Gen-2 smoke binary |
| `build/bootstrap-loop-gen2-full-spine` | Gen-2 full spine (717/717) |
| `build/bootstrap-loop-gen3-full-spine` | Gen-3 full spine (717/717) — **gen-2 output** |

---

## Presenter commands (copy-paste)

**Full M4 loop (smoke + spine + gen-3):**

```bash
script/apply-patches.sh
make bootstrap-loop-gen1-link                    # gen-1 → gen-2 smoke (native)
make bootstrap-loop-gen1-full-spine-emit       # gen-1 → gen-2 spine (717/717)
make bootstrap-loop-gen2-recompile-spine       # gen-2 → gen-3 spine (717/717)
```

**One-shot probes:**

```bash
make bootstrap-loop-probe              # M2 + M3 strict + gen-1→gen-2 + gen-2→gen-3
make bootstrap-native-compile-driver-smoke   # build bin-compile-aot + smoke compile
make bootstrap-selfhost-driver-smoke         # helloworld driver → gen-2 smoke + run
make north-star4-verify                      # inventory + M3 strict + M4 ladder
make north-star5-verify                      # M5 vendor + spine presenter
```

**Docker:**

```bash
./script/docker-exec.sh -- bash -lc '
  source script/php-env.sh
  make bootstrap-loop-probe
'
```

---

## Environment (gen-2 native compile)

Gen-1 link defaults (see `./script/bootstrap-loop-gen1-link.sh`):

- `BOOTSTRAP_M4_LINK_COMPILE_DRIVER=1` — link M3 compile-driver TU for native emit (`emit_path=native` when LLVM present)
- `BOOTSTRAP_M4_RUNTIME_COMPILE=1` — run gen-1 emit helper at link time
- `BOOTSTRAP_M4_GEN2_STRICT=1` — opt-in: refuse Zend fallback (`emit_path=zend_fallback_would_be_used`)

The native driver expects M3 env at runtime (not argv `-o`):

```bash
export PHP_COMPILER_M3_SOURCE=/path/to/source.php
export PHP_COMPILER_M3_OUT=/path/to/output-binary
./build/bin-compile-aot
```

Expect stdout: `compile_smoke_m3_emit: compile OK -> …` (or `helloworld_compile_smoke:` variant).

---

## What is still open (M4 → M5)

| Gap | Tracker |
|-----|---------|
| Full argv `bin/compile.php -o` on compiled driver | [#1937](https://github.com/PurHur/php-compiler/issues/1937), [#2633](https://github.com/PurHur/php-compiler/issues/2633) |
| Gen-3 compiles **changed** tree (not just re-link same revision) | [#1498](https://github.com/PurHur/php-compiler/issues/1498) |
| No `vendor/` cold boot | [#1416](https://github.com/PurHur/php-compiler/issues/1416) |
| `php-cfg` / `php-llvm` vendor prelink (`Expr_Closure`) | [#1416](https://github.com/PurHur/php-compiler/issues/1416) |

---

## Related docs

- [self-host-target.md](self-host-target.md) — milestone ladder M0–M5
- [bootstrap-selfhost.md](bootstrap-selfhost.md) — gate table (all make targets)
- [bootstrap-m5-fast-path.md](bootstrap-m5-fast-path.md) — M3 compile-driver lowering
- [GETTING-STARTED.md](GETTING-STARTED.md) §7 — North Star 4 presenter
