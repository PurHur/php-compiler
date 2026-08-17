# Agent handover — 2026-08-17

Written after a session that started by re-establishing ground truth on a tree that had moved
3,069 commits since the previous handover. Every number below was measured in
`php-compiler:22.04-dev`; nothing is quoted from memory or from a prior document.

Where a claim is **not** verified, it says so. Please keep that habit — two of the findings here
exist only because an earlier claim in `docs/roadmap/RELEASE-PLAN.md` was believed rather than
re-checked.

---

## 1. The headline: the compiler could not produce a working binary

`script/aot-smoke.sh` on master at `8084f14996`:

```
aot-smoke: 0 passed, 8 failed
```

Every one of the eight trivial programs — `echo`, arithmetic, concat, function call, branch, loop,
array, class — failed at **link**, identically:

```
/opt/llvm9/ld: loop.bin.o: in function `phpc_gc_collect_cycles_impl':
main:(.text+0x47afe): undefined reference to `memset.1'
```

### Cause

`GcCollectCyclesRuntime::ensureExternal()` called `module->addFunction()` whenever
`lookupFunction()` threw, without first checking `getNamedFunction()`:

```php
try {
    $context->lookupFunction($name);
} catch (\Throwable $e) {
    $fn = $context->module->addFunction($name, $ft);   // no getNamedFunction() check
    $context->registerFunction($name, $fn);
}
```

`LibcExtern` adds `memset` to the module **and gives it a body** via `implementMemsetBody()`, but
does not always leave it in the context registry. So `lookupFunction()` throws while the symbol
already exists. `addFunction()` on an existing name does not fail — LLVM silently renames the
second one to `memset.1`, which carries no body.

Fixed in **#31894 (merged)** by reusing the existing declaration, matching the
`getNamedFunction()`-first pattern `LibcExtern` already used. Verified `0/8 → 8/8`.

### Two "separate" bugs that were this one wearing a costume

Both now match Zend exactly:

| probe | before | after |
|---|---|---|
| `var_dump(7)` | abort, rc=134 | `int(7)` |
| `(new Exception)->getMessage()` | segfault | `msg=[]` |

Anything diagnosed against a tree where AOT cannot link is measuring the link failure, not the
feature. Run `script/aot-smoke.sh` **first**, every time — that is exactly what it is for, and its
own failure message says so.

---

## 2. Why nobody noticed: the gate was red for an unrelated reason

`compiler-gate.yml` had failed **19 of its last 20 master pushes** — and never on the checks it
exists to run. It died during setup:

```
ERROR: failed to apply php-cfg-bare-variable-read-stmt.patch
error: corrupt patch at line 47
```

A hunk header in `patches/php-cfg-bare-variable-read-stmt.patch` declared 24 new lines over a body
containing 23 (3 context + 17 added + 3 context). `git apply` reads to the declared count, runs off
the end of a 46-line file, and reports the file as corrupt. `d7134a6625` (#31881) added a line and
did not update the count.

`apply-patches.sh` runs **before every check**, so the gate never reached `aot-smoke.sh` — the one
check that would have caught §1 on the commit that introduced it.

**This is the finding worth internalising.** A gate that is permanently red for an irrelevant reason
carries exactly as much information as one that is permanently green. Nobody reads the log of a job
that always fails; they read the colour, see red, and merge, because red is the normal colour. The
repo already had a catalogue of checks that reported success without executing — this is the same
defect with the sign flipped.

**Suggested follow-up:** make a setup failure and a check failure *look different*. A gate should be
able to say "I could not run" in a way that is visibly distinct from "the code is broken".

### Status: partially fixed

**#31909 (open)** corrects the header to `+1426,23`. Measured on that branch
(run `32004632440`): the `corrupt patch` abort is gone and the sequence advances past that patch —
then fails on the **next** one, `php-cfg-match-multi-cond-block.patch`. It is a necessary step, not
the whole fix.

**A trap for whoever picks this up.** That next patch has the *same* arithmetic inconsistency
(`@@ -2001,6 +2001,9 @@` over a 7-old/10-new body), so the obvious move is to "fix" it the same way.
**That is not the cause.** `git apply --check -p0` on it returns **rc=0** — git tolerates the
miscount. The real problem is context: its anchor line

```php
$testBlock = $nextBlock;
```

**does not exist anywhere in pristine `ircmaxell/php-cfg`** (checked against `72df227` in the
composer cache). The hunk depends on context introduced by an earlier patch in the match-lowering
family. Correcting the header would produce a change that looks like a fix, verifies nothing, and
buries the real problem a layer deeper.

Diagnosing it properly needs a fresh `composer install` to reproduce CI's vendor state. That was not
possible during this session (10 agents were using the live `vendor/` tree; a worktree attempt timed
out at load ~130 on a 16-core box).

---

## 3. AOT correctness is much weaker than the release plan implies

A 10-agent differential hunt was run: each agent takes one language area, writes 15–25 deterministic
probes, and compares AOT output against Zend byte-for-byte, with adversarial per-finding
verification.

Two areas had reported at the time of writing:

| area | probes run | mismatches |
|---|---|---|
| statics | 41 | 21 |
| numerics | 44 | 23 |
| **total** | **85** | **44** |

A ~50% mismatch rate on ordinary PHP. See `docs/roadmap/AOT-CORRECTNESS-PLAN.md` for the grouped
inventory and the order to attack it in.

### Confound, stated precisely

The hunt ran while the box was at load 129–228 on 16 cores (mostly *unrelated* long-running jobs —
seven `zahlenjagd` processes had each held ~97% of a core for 18 days). A wall-clock timeout under
that load would be a contention artifact.

It does not invalidate the haul: the findings carry **deterministic** signatures — exit 2/255 with
specific compiler error strings, exit 139 (SIGSEGV), exit 134 (SIGABRT). Those are not produced by a
busy machine. No finding of kind `hang` was reported. Re-verify anyway before acting; some findings
in this document are probe-reported and not yet independently reproduced, and they are marked.

---

## 4. What is verified, and what is not

**Verified by me, in the pinned image, exit codes read directly:**

- `aot-smoke` 0/8 → 8/8 after #31894
- `var_dump(7)` and `Exception::getMessage()` now match Zend
- inherited property defaults are broken under AOT (#31895) — see below
- the `corrupt patch` error is gone after #31909, and the gate advances one patch further
- `$testBlock = $nextBlock` is absent from pristine php-cfg

**Not verified — do not treat as established:**

- that `apply-patches.sh` succeeds end to end after #31909
- the 44 mismatches beyond the four re-tested by hand; they are probe-reported
- anything about the eight areas that had not reported when this was written

---

## 5. Open issues filed

| issue | what |
|---|---|
| #31895 | AOT: inherited property defaults are never initialised — every subclass loses its parent's defaults |
| (see plan) | grouped AOT correctness issues, listed in `docs/roadmap/AOT-CORRECTNESS-PLAN.md` |

`#31895` is worth reading as a template for the rest: minimal repro, a Zend-vs-AOT table, and an
explicit note that the untyped variant produces *silently wrong output* rather than an error.

---

## 6. Operational notes for the next agent

- **This box is the production server** (~53 containers: leitstand, lakehaus, and others). Check
  `uptime` before starting compile fan-out. Do not add compile load above ~load 60.
- **Concurrent containers sharing the bind-mounted helper cache corrupt each other.** Give every
  concurrent `docker run` its own `PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR`.
- **Never run `composer install` or `git worktree add` against `/root/php-compiler` while agents are
  using that tree.**
- **Keep whole-corpus compliance runs manual.** `compliance-pr.yml` and `compliance-host-compare.yml`
  are `workflow_dispatch`-only for a reason: an earlier 48-job-per-PR trigger queued ~2,830 jobs and
  took repository CI down for ~7 hours.
- `docs/roadmap/RELEASE-PLAN.md` is **stale** (dated 2026-07-29) and still claims
  *"P1.1 CI on `lib/`, `ext/` — done, green on master"*. That was false for a long stretch. Update it
  once the gate genuinely reports.
