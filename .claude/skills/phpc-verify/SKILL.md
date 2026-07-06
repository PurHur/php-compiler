---
name: phpc-verify
description: Pick the cheapest sufficient verification gate for a php-compiler change and run it — the tiered ladder from 20 ms probes to the 1 h strict self-host gate. Use before every commit/PR, and to decide "which test do I run for this diff?"
---

# php-compiler tiered verification ladder

Always run the **smallest gate that can catch your mistake**, then one ladder step above it before merging. Wall times measured 2026-07 on a dev server.

| # | Gate | Wall time | Run when you touched |
|---|------|-----------|----------------------|
| 1 | `php script/bootstrap-inventory.php --check` | seconds | anything on the `bin/vm.php` dependency path |
| 2 | `./script/check-generated-docs.sh` | < 30 s | builtin registration, advertisement gates (`lib/CompilerVersion.php`), composer.json, ext/ modules |
| 3 | `./script/phpunit.sh --filter <TestClass>` | seconds–min | one subsystem (e.g. `--filter VMTest`, `--filter SodiumTest`) |
| 4 | `./script/ci-fast.sh` or `./phpc test --fast` | ~5–15 min | anything in lib/, ext/, bin/; **mandatory before every PR** |
| 5 | `make north-star5-verify-fast` (Docker) | ~2–5 min | bootstrap/spine/gen-0/self-host paths |
| 6 | `./script/ci-local.sh` | longer; downloads LLVM 9 to `.llvm/` on first run | pre-merge for compiler/JIT/AOT changes |
| 7 | `make north-star5-verify ARGS=--strict` | ~1 h | ONLY before merging bootstrap/gen-0/vendor-prelink work |

Docker wrapper for any gate: `./script/docker-exec.sh -- bash -lc 'source script/php-env.sh && <gate>'`.

## Diff-class → minimum pre-merge set

- **Docs only / generated docs**: #2 (drift check) — nothing else
- **One builtin (ext/…)**: #3 targeted phpunit + #2 (advertisement drift!) + #4
- **VM/compiler core (lib/)**: #3 + #4; add #6 if opcode lowering/JIT touched
- **Bootstrap / spine / prelinked**: #1 + #5; #7 before merge
- **composer.json**: #2 + fresh `composer install` in Docker

## Execution discipline

- Run gates ≥ 5 min in **background**, tail the log, keep working.
- A red gate on your branch: first check whether **master** is red too (`git stash && <gate>`) before debugging your diff.
- AOT smoke one-liner: `./script/docker-exec.sh -- bash -lc './phpc build -o /tmp/x FILE.php && /tmp/x'`
- Self-host probe (~20 ms, after spine edits): `make bootstrap-selfhost-vm-driver-execute-probe`
- Full spine relink is only needed after editing `compiler_lib_spine_smoke/main.php` or with `BOOTSTRAP_VM_DRIVER_EXECUTE_PROBE_FULL_LINK=1`.
- ci-fast runs the **AOT build smoke before the hygiene layers** (#16882): if the smoke is red, your diff (or a fresh master regression) broke AOT — do not "fix the docs first".
- `docker run … | tail` masks a gate's exit code — log to a file and check `$?`, or use `exit ${PIPESTATUS[0]}`.

## Long battery runs (full phpunit)

Two known hang patterns need a babysitter (until #16657/#15906 land): phpunit leaves `bin/vm.php` children in `T` (stopped) state, and the big loop test never exits. Run inside docker-exec:

```bash
while true; do
  for pid in $(ps -eo pid,stat,comm | awk '$2 ~ /T/ && $3 ~ /php/ {print $1}'); do kill -CONT $pid; done
  for pid in $(ps -eo pid,etimes,args | awk '$2 > 720 && /bin\/vm\.php/ && !/awk/ {print $1}'); do kill -9 $pid; done
  sleep 20
done &
php -d memory_limit=1536M vendor/bin/phpunit --exclude-group llvm,serve,cgi
```

## Debugging JIT/AOT failures fast

- **LLVM segfault or module-verify failure**: rerun with `PHP_COMPILER_LLVM_ASSERT=1` — the php-llvm patch validates `structGep` receivers/indices and `zExt` operands at the FFI boundary and throws a PHP exception **with a backtrace naming the guilty lowering** (this converted the #16565 fixed-offset crash from a weeks-long mystery to a minutes-long read; 456 structGep call sites are covered).
- **Raw segfault with no PHP frame**: `apt-get install -y gdb` in the container, then `gdb -batch -ex run -ex "bt 25" --args php <cmd>` — the top LLVM-C API frame tells you which builder call to assert on next.
- **Bundle/spine emit dies with no file context**: check `build/.last-jit-func` first (`c:main_*` = top-level code of an inlined include); IncludeHelper emits per-include breadcrumbs — the last include named before the failure is the culprit. Never bisect 4k spine requires; instrument the throw site instead (each full-bundle compile is ~19 min).
- The classic bug shape behind most of these: using a `Variable`'s raw `->value` where the *loaded* value is required — a KIND_VARIABLE object receiver is an `__object__**` slot, a box-backed "native" arg loads as a `%__value__` struct. Always go through `helper->loadValue()` / `JitValueBox::valuePtrFromVariable()`.
