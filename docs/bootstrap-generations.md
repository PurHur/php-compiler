# Bootstrap generations — compiler compiles the compiler

**North star:** [#1492](https://github.com/PurHur/php-compiler/issues/1492) · **M4 loop:** [#1498](https://github.com/PurHur/php-compiler/issues/1498) · **Native driver:** [#2697](https://github.com/PurHur/php-compiler/pull/2697)

This document is the **canonical reference** for generation numbering, artifacts, and presenter commands. Use it when updating docs, Makefile targets, or CI sync checks.

---

## Generation ladder (May 2026, verified LLVM 9)

| Gen | Built by | Artifact | What it proves |
|-----|----------|----------|----------------|
| **0** | Zend `php bin/compile.php` **or** committed `prelinked/bootstrap-gen0/bin-compile-aot` | `build/selfhost-helloworld-compile`, emit helpers | M3 emit TU links; `BOOTSTRAP_M5_NO_ZEND=1` uses prelinked gen-0 driver only ([#3053](https://github.com/PurHur/php-compiler/issues/3053)) |
| **1** | Gen-0 emit helper | `build/bootstrap-loop-gen1-compile` | Native compile driver (M3 bridge) is linkable |
| **2 (smoke)** | Gen-1 emit helper | `build/bootstrap-loop-gen2` | Gen-1 **native-emits** a smoke fixture (`compiler smoke`) |
| **2 (spine)** | Gen-1 full-spine emit | `build/bootstrap-loop-gen2-full-spine` | Gen-1 **native-emits** the **726/726** spine bundle |
| **3** | Gen-2 native driver | `build/bootstrap-loop-gen3-full-spine` | Gen-2 **recompiles** the **726/726** spine via argv `-o` (no `PHP_COMPILER_M3_*` on compile; [#2866](https://github.com/PurHur/php-compiler/issues/2866)) |

**Gen-2 compiles itself** in the M4 sense: `build/bin-compile-aot` (M3 native compile driver, linked via `./script/bootstrap-loop-gen1-link.sh` or `bootstrap-selfhost-helloworld-compile-bin.sh` for `bin/compile.php`) emits the next native binary via **argv** `-o OUT SOURCE.php` (preferred; [#2866](https://github.com/PurHur/php-compiler/issues/2866)) or legacy `PHP_COMPILER_M3_SOURCE` / `PHP_COMPILER_M3_OUT`. Zend **fallback** (`emit_path=zend partial`) remains in gen-1 link when native emit is blocked. Inventory-scale spine uses link-time sidecar fast paths inside the emit bridge; full `php_compiler_cli_dispatch` lowering for arbitrary PHP remains ([#1937](https://github.com/PurHur/php-compiler/issues/1937), [#2866](https://github.com/PurHur/php-compiler/issues/2866)).

---

## Key artifacts

| Path | Role |
|------|------|
| `build/bin-compile-aot` | Native compile driver (M3 emit helper linked for `bin/compile.php`) |
| `build/bootstrap-loop-gen1` | Gen-1 smoke bundle (runs before emit) |
| `build/bootstrap-loop-gen1-compile` | Gen-1 emit helper |
| `build/bootstrap-loop-gen2` | Gen-2 smoke binary |
| `build/bootstrap-loop-gen2-full-spine` | Gen-2 full spine (726/726) |
| `build/bootstrap-loop-gen3-full-spine` | Gen-3 full spine (726/726) — **gen-2 output** |

### Gen-0 compile invoker (`bootstrap-resolve-compile-invoke.sh`, #2894)

| Priority | Artifact | When used |
|----------|----------|-----------|
| 1 | `build/selfhost-compile-driver` | M5 host-linked `bin/compile.php` (optional) |
| 2 | `build/bin-compile-aot` | After `make bootstrap-selfhost-driver-smoke` — argv driver + M0 `compiler_minimal` sidecar |
| 2b | `prelinked/bootstrap-gen0/bin-compile-aot` | Committed gen-0 seed; `script/bootstrap-gen0-seed.sh` copies into `build/` ([#3053](https://github.com/PurHur/php-compiler/issues/3053)) |
| 3 | `build/selfhost-native-compile-driver` | Emit-helper alias (same bytes as `bin-compile-aot`; last resort) |
| — | Zend `php bin/compile.php` | Empty `build/` when seed not used; `BOOTSTRAP_M5_NO_ZEND=1` forbids Zend |

`make bootstrap-selfhost-link-compiled` runs driver-smoke once if `build/bin-compile-aot` is missing (`BOOTSTRAP_GEN0_ENSURE_COMPILED_DRIVER=1`). Resolver tries **each** native candidate before Zend fallback.

---

## Presenter commands (copy-paste)

**Full M4 loop (smoke + spine + gen-3):**

```bash
script/apply-patches.sh
make bootstrap-loop-gen1-link                    # gen-1 → gen-2 smoke (native)
make bootstrap-loop-gen1-full-spine-emit       # gen-1 → gen-2 spine (726/726)
make bootstrap-loop-gen2-recompile-spine       # gen-2 → gen-3 spine (726/726, argv -o)
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

## Gen-2 native compile (argv preferred)

Gen-1 link defaults (see `./script/bootstrap-loop-gen1-link.sh`):

- `BOOTSTRAP_M4_LINK_COMPILE_DRIVER=1` — link M3 compile-driver TU for native emit (`emit_path=native` when LLVM present)
- `BOOTSTRAP_M4_RUNTIME_COMPILE=1` — run gen-1 emit helper at link time
- `BOOTSTRAP_M4_GEN2_STRICT=1` — opt-in: refuse Zend fallback (`emit_path=zend_fallback_would_be_used`)

**Preferred** — production-shaped argv (gen-2→gen-3 spine, driver smoke; [#2866](https://github.com/PurHur/php-compiler/issues/2866)):

```bash
./build/bin-compile-aot -o build/bootstrap-loop-gen3-full-spine \
  test/selfhost/compiler_lib_spine_smoke/main.php
```

Expect stdout: `compile_smoke_m3_emit: compile OK -> …` (or `helloworld_compile_smoke:` variant).

**Legacy** — env dispatch (gen-1 emit helper, some probes):

```bash
export PHP_COMPILER_M3_SOURCE=/path/to/source.php
export PHP_COMPILER_M3_OUT=/path/to/output-binary
./build/bin-compile-aot
```

---

## What is still open (M4 → M5)

| Gap | Tracker |
|-----|---------|
| Full `php_compiler_cli_dispatch` compile (no emit-helper sidecar) on inventory spine | [#2866](https://github.com/PurHur/php-compiler/issues/2866), [#1937](https://github.com/PurHur/php-compiler/issues/1937) |
| Gen-3 compiles **changed** tree (not just re-link same revision) | [#1498](https://github.com/PurHur/php-compiler/issues/1498) |
| No `vendor/` cold boot | [#1416](https://github.com/PurHur/php-compiler/issues/1416) |
| `php-cfg` / `php-llvm` vendor prelink (`Expr_Closure`) | [#1416](https://github.com/PurHur/php-compiler/issues/1416) |

---

## Related docs

- [self-host-target.md](self-host-target.md) — milestone ladder M0–M5
- [bootstrap-selfhost.md](bootstrap-selfhost.md) — gate table (all make targets)
- [bootstrap-m5-fast-path.md](bootstrap-m5-fast-path.md) — M3 compile-driver lowering
- [GETTING-STARTED.md](GETTING-STARTED.md) §7 — North Star 4 presenter
