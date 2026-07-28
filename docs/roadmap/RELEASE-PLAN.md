# php-compiler → release: steering plan

Written 2026-07-26. Every claim below was measured this session; sources are named so each can be
re-checked rather than believed.

## Diagnosis

The project has a great deal of verification **ceremony** — gates, stamps, spine sync, generated-doc
checks — but the load-bearing checks do not run on the code that matters. So defects and
non-functional artifacts ship while the gates report green.

Evidence, all verified today:

| finding | evidence |
|---|---|
| **No CI on the compiler itself** | `.github/workflows/bootstrap-spine-gate.yml` is the only workflow, and its `pull_request` trigger carries a `paths:` filter excluding `lib/JIT/**`, `lib/VM/**`, `ext/**`. A PR touching lowering gets **zero checks**, and `gh pr view` reports `MERGEABLE/CLEAN` — which means "no checks configured", not "checks passed". |
| **Silent wrong output on ordinary PHP** | 24 of 43 mundane programs mismatched Zend. `f($x+1, $x+2)` printed `12 12`; `str_replace($p['from'], $p['to'], 'xy!')` returned `xy!`. None caught by the compliance suite. Fixed in #23356/#23424. |
| **The shipped compiler does not work** | `prelinked/bootstrap-gen0/bin-compile-aot` fails `parseAndCompile` on *every* input including hello-world (#23468). Driver bytes are from 2026-06-15; 6441 lowering commits since; manifest restamped 272 times, provenance `unverified-restamp`. |
| **A core cache was silently off for 20 days** | Committed helper-runtime fingerprint `1771b935…` vs live `6497e66d…`. On mismatch every unit is skipped, so a two-line script recompiled ~257 units across 13 cores. Nothing noticed. Refreshed in #23457. |
| **The AOT backend is wrong on its own benchmarks** | `mandelbrot` renders all `_` (#23471); `Ack(3,8)` segfaults (#23472). Both AOT-only — Zend and the VM are correct. These are the benchmarks the project quotes speedups from. |
| **The test suites are too slow to run** | `VMTest` ≈ 4 h serial; `JITTest` did not finish in 40 min. So they are not run per-change, and both carry large pre-existing failure sets (407 and 472). |
| **Tooling could hang forever** | `script/bench.php` had no timeout: `bin/vm.php` on `Ack(3,10)` ran 38 min at 100% CPU (Zend: 1.9 s) and blocked the whole suite. Fixed today. |

The through-line: **it is currently cheaper to make a gate green than to make the thing work.**
Restamping satisfies freshness; a copy satisfies "native emit"; an empty filter satisfies CI.

## Strategy

Make truth cheap and lies expensive. Concretely: every claim the repo makes about itself should be
produced by a check that fails when the claim stops being true, and no claim should be satisfiable
by restamping or copying.

Work in this order. Each phase exists because the next one is worthless without it.

---

## Phase 1 — Make correctness observable (days, highest leverage)

Nothing else matters while defects can land unnoticed.

1. **CI on `lib/**`, `ext/**`.** ~~Today: zero.~~ **LANDED 2026-07-28** —
   `.github/workflows/compiler-gate.yml` runs on `lib/**`, `ext/**`, `bin/**`, `patches/**` and the
   differential corpus. Cheap tier, in order: nikic preflight (18 s) → `script/aot-smoke.sh` →
   differential sweep (VM). Measured on master: preflight 0, smoke 8/8, VM **109/109**, ~12 min.

   The AOT sweep runs as a **separate, non-gating** job behind `workflow_dispatch`, because it is
   not green on master and AGENTS.md §2 requires comparing failing case *names* against
   `AOT-BASELINE.md` — a human judgement. Failing a job on its exit status would make adding a case
   that pins a known bug look like a regression, and the usual response to that is to stop adding
   cases.

   **Why the smoke test is the load-bearing part:** on 2026-07-28 three commits made *every* AOT
   binary fail — #24188 and #24196 (startup segfault, #24194) and #24227 (unlinked symbol, #24230).
   All three were caught by hand and reverted (#24195, #24197, #24231). `script/aot-smoke.sh`
   returns 0/8 with exit 1 on the first of those commits and 8/8 on master, so it distinguishes
   "the toolchain is broken" from "a feature is broken" in ~90 s. Every sweep run during those
   windows reported mass failure that had nothing to do with what it was testing.
2. **Differential sweep as a gate**, VM *and* AOT (`script/differential-sweep.sh`, merged #23444).
   It found all 24 argument defects and would have caught #23471/#23472. Extend cases toward the
   shapes users actually write.
3. **Make the suites runnable.** Sharding takes `VMTest` from ~4 h to ~20 min (24 shards, per-test
   TeamCity output so a stalled shard still reports). This session's harness works; land it as a
   script rather than leaving it in a scratchpad.
4. **Quarantine flaky tests explicitly.** `hrtime_*` (#23420), `proc_get_status_basic`,
   `interface_abstract_static_call`, `dnf_return_type_error` flip between runs and make regression
   comparison noisy — one appeared on the *fixed* list in one comparison and the *regressed* list in
   the next. Name them in a quarantine list; treat entry to that list as a bug to fix, not a
   dumping ground.

**DECISION:** compare suites by *set difference of failing case names*, never by count — neither
suite is green on master, so a count is meaningless.

## Phase 2 — Stop shipping artifacts that do not work (weeks)

5. **Fix the AOT correctness defects** (#23471 mandelbrot, #23472 Ack segfault). Until these are
   closed, no speed claim about generated code is defensible.
6. **Gate the gen-0 driver on function, not on a stamp** (#23468): the committed driver must compile
   a script it has never seen, produce a runnable binary, and match Zend. A driver that starts and
   fails `parseAndCompile` currently satisfies every existing check.
7. **Close the restamp loophole.** #22966 added build receipts, but `BOOTSTRAP_GEN0_ALLOW_UNVERIFIED_STAMP`
   is still exercised — two `Trust: restamp gen-0 fingerprint` commits landed today alone (#23429,
   #23438). Either the escape hatch requires a recorded reason and shows as degraded in
   `release-readiness`, or it goes.

**OPEN:** does any consumer actually need the gen-0 driver per-commit, or only per-release? If the
latter (likely), the per-commit freshness gate is the wrong shape entirely — see Phase 3.

## Phase 2.5 — Side-load extensions (structural, highest leverage on build cost)

**DECISION (2026-07-26, user):** the compiler core must not include every extension by default.
Extensions become separate, discoverable, side-loadable modules; third parties can add one without
patching the core.

Follow the php-src shape: **core + `ext/standard` are mandatory; every other extension is built
either statically (opt-in) or shared (side-loaded)**, and a new extension is a directory, not a core
edit.

Sizing that split:

| group | files |
|---|---|
| `lib/` (compiler, VM, JIT, AOT) | 1,882 |
| `ext/standard` (mandatory, as in php-src) | 2,127 |
| rest of default set (`spl`, `hash`, `random`, `ctype`, `types`) | 126 |
| **core total** | **4,135** |
| optional extensions (70 modules) | 2,473 |

Regenerate with `php script/extension-inventory.php` (`--json` for machine output) — these are
measured, not estimated, and the numbers above will drift as `ext/` grows. Audited 2026-07-28:
**76 directories under `ext/`, all 76 loaded unconditionally** by `Runtime::loadCoreModules()`, with
no phantom entries and no directory carrying a `Module.php` that is never loaded. So the load list
is currently complete and consistent — the problem is not drift, it is that the set is hardcoded and
non-optional.

The optional 2,473 files are **37% of the 6,608 total**. Every compiled binary pays for all of them
today.

**CORRECTION to an earlier estimate:** I previously said this takes gen-0 from ~6,500 files to
~1,850. That was wrong — it assumed `ext/standard` leaves the core, which it does not under the
php-src model. The honest figure is **6,519 → 4,101, a 37% cut**.

### Why the build is slow — it is not the file count

php-src builds a comparable amount of C in minutes. The difference is not size, it is **translation
unit granularity**:

| | php-src | php-compiler today |
|---|---|---|
| unit of compilation | one `.c` → one `.o` | **6,519 files → one LLVM module** |
| parallelism | `make -j` across objects | single-threaded (observed 98% of one core on 16) |
| incrementality | touch one `.c`, rebuild one `.o` | any lowering edit invalidates everything |
| failure recovery | rebuild the failing object | ~4.6 h discarded, no checkpoint |

That is the whole answer to "why does it take so long". 4,101 files compiled as one unit will still
be slow; 6,519 files compiled as 6,519 objects would not be.

The repo already proves the per-unit model works: the helper-runtime cache emits 266 units in
**4m27s parallel vs ~45 min serial** (`PHP_COMPILER_EMIT_JOBS`), and a single helper edit re-emits a
single unit. That machinery is the template — it needs applying to the spine, not inventing.

So Phase 2.5 has two halves, and the second matters more:

1. **Boundary** — extensions leave the mandatory core (37% fewer files, smaller binaries, real
   extensibility).
2. **Granularity** — per-module translation units, emitted in parallel, linked afterwards, so an edit
   re-emits one object. This is what turns 4.6 h into minutes, and it is the same partition the
   boundary work creates.

Direction:

- **Discovery over hardcoding** — a per-extension manifest (name, provided symbols, dependencies,
  capability rows) enumerated at build time, replacing 75 constructor calls.
- **Opt-in linking** — the extension set is a property of the build (`phpc.json` / CLI), with a small
  default; `--with-ext=` / `--without-ext=` rather than all-or-nothing.
- **Link what is reachable**, with an explicit override for dynamic use (`$fn = 'curl_init'`).
- **No core → ext imports**: dependencies point ext → core, never the reverse.
- **Watch the boundary**: an unbound cross-module call lowers to `__value__writeNull` with no
  diagnostic (#579) — run `PHP_COMPILER_REPORT_EXTERNAL_STUBS=1` when moving module edges.

**Acceptance test:** a script that never calls into an extension pays nothing for it — not in link
time, not in binary size, not in startup. Report gen-0 file count, hello-world binary size and cold
build wall time before and after.

### MEASURED 2026-07-28 — for user binaries this is ALREADY TRUE

Ran that acceptance test rather than assuming it. Regenerating `lib/ExtensionRegistry.php` with 6
extensions instead of 76 and rebuilding hello world:

| registry | hello-world binary | cold build |
|---|---|---|
| all 76 extensions | 17,148,584 B | 6 s |
| 6 extensions (`standard, spl, types, ctype, hash, random`) | **17,148,944 B** | 6 s |

The minimal build is **360 bytes larger** — i.e. no change. Two further measurements explain why:

- `nm` finds **zero** `curl`/`mongodb`/`snmp`/`ldap` symbols in a hello-world binary built with the
  full 76-extension registry. Unused extensions are not linked in at all.
- A script that *does* call an extension grows the binary by ~572 KB (`bcadd`), so linking is
  demand-driven and already working.

The binary is dominated by `.text` at ~15 MB — the runtime and compiler core, not extensions.

**Consequence: Phase 2.5 must not be justified by user binary size or user build time.** That
benefit already exists. The two justifications that survive are:

1. **Extensibility** — "a new extension is a directory, not a core edit". Delivered by the generated
   registry (#24418): the 76 hardcoded loads are gone, and `--only=` / `--without=` select a set at
   build time with declared dependencies pulled in automatically.
2. **The compiler's OWN build cost** — the 6,519-file spine compiled as one translation unit, which
   is the multi-hour gen-0 problem. That is real, and it is what the per-module translation-unit
   split in the second half of this phase addresses.

This correction matters because the phase is sized above at "37% fewer files", which implies a
proportional win for users. Measured, that win is not there to collect.

Selection happens at generation time, not runtime, and that is forced: the registry emits literal
`new` expressions which the AOT compiler resolves statically, so a referenced module is compiled in
regardless of any runtime filter. Dropping the cost means dropping the reference.

**OPEN:** which extensions form the default set? Suggest the ones the language itself leans on
(`standard`, `spl`, `types`, `ctype`, `hash`, `random`) and treat the rest as opt-in.

## Phase 3 — Break the artifact treadmill (structural, weeks)

The recurring failure is a committed artifact keyed on a fingerprint that changes several times a
day. Master merges ~4 commits/hour, ~56% touching lowering sources. Any artifact costing more than
~7 minutes to rebuild is stale on arrival, so it gets restamped instead.

8. **Helper cache: per-unit dependency keys** (#23458). Today one global `core_fingerprint` covers
   `lib/JIT.php`, `lib/Runtime.php`, … so one edit invalidates all 257 units. Key each unit on the
   closure it actually reached at emit time — the emitter already walks it.
9. **Gen-0: split-TU build.** The spine is ~96% partitionable (#23018/#23033: only 3–4% of
   intra-spine class references cross a directory boundary). A single 6,519-file translation unit
   running ~4.6 h and OOMing becomes incremental, parallel and resumable — the model already exists
   in `HelperRuntimeCache` + `emit-helper-runtime-object.php`.
10. **Treat gen-0 as a release artifact, not a per-commit invariant.** Rebuild under a merge freeze,
    verify by function, tag, ship. Between releases, report staleness honestly
    (`bootstrap-gen0-staleness.php` already does) instead of restamping to green.

## Phase 4 — Performance, with evidence (after 1–3)

11. **Benchmarks that cannot hang** — done today: per-run cap, separate build cap, `n/a` with a
    reason rather than a stall.
12. **Only quote numbers traceable to a committed table** where every runtime produced *identical
    output*. The current published AOT column was measured on binaries that are wrong or crash.
13. **Then optimise**, driven by profiles. The known hot spots are already measured: the php-cfg
    `Simplifier` quadratic path is 57% of gen-0 compile samples with a linear path sitting behind
    an opt-in env var, and helper-unit emit is O(units × transitive closure).

**DECISION:** no optimisation work before Phase 2 closes. Optimising a binary that renders
`mandelbrot` wrong is effort spent making a wrong answer arrive sooner.

---

## Release criteria for v1.1.0

Falsifiable, and each one is a command someone can run:

1. `script/differential-sweep.sh` green on **VM and AOT**.
2. Committed gen-0 driver compiles an unseen script and matches Zend.
3. Compliance suites: **no regressions** vs the previous release by case name, flaky set quarantined
   and named.
4. `benchmarks/README.md` regenerated with **no `n/a`** in any column, every runtime output-verified.
5. No open defect of class "silent wrong output".

## What not to do

- Do not optimise before Phase 2. Wrong-but-fast is worse than slow-but-right.
- Do not add gates a restamp or a copy can satisfy. The false-green family (#21860: hardcoded
  `emit_path=native`, copy-fallback reported as native emit, substring match on
  `native-prelinked-sidecar`) is the template for what to avoid.
- Do not chase the gen-0 rebuild as a per-commit invariant. Arithmetic says it cannot be one.
- Do not trust `CLEAN` on a PR as evidence of anything until Phase 1 item 1 lands.

## Sequencing note

Phase 1 items 1–3 are days of work and unblock everything else, because they turn "did I break
something?" from a 4-hour question into a 20-minute one. That single change is what makes the rest
of this plan executable at the project's actual merge velocity.
