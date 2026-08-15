---
name: phpc-fix-loop
description: The smallest-gate-first edit→verify→PR loop for a php-compiler code change, including which generated docs to resync for which change class and the PR/merge protocol. Use when implementing any fix or feature in this repo.
---

# php-compiler fix loop

## Loop shape (fast feedback first)

1. **Repro first**: write/locate a minimal repro under `test/repro/` (or a failing `--filter` PHPUnit case). Run it on the VM via Docker (RunForge forbids host PHP):
   `./script/docker-exec.sh -- bash -lc 'php bin/vm.php test/repro/<x>.php'`. Seconds.
2. **Edit** the narrowest layer: prefer PHP lowering (`lib/`) / module registration (`ext/<mod>/`) over C runtime branches.
3. **Verify inner loop**: re-run the repro + targeted `./script/phpunit.sh --filter <Class>` (or `./script/docker-exec.sh -- bash -lc 'source script/php-env.sh && vendor/bin/phpunit --filter …'`). **Never** bare `php vendor/bin/phpunit` on Runforge — host PHP has unlimited memory and can OOM the machine. Iterate here — never iterate on full gates.
4. **Resync generated docs** (see table below) or the fast gate will go red for the *next* agent.
5. **Ladder up**: `./phpc test --fast`, plus the tier the diff class demands (see phpc-verify skill).
6. **PR**: branch `fix/<issue>-<slug>`, commit message `<Category>: <what> (#<issue>)`, body = problem → root-cause commit SHA → fix → verification transcript. All merges via PR (CONTRIBUTING), gates are local-only (remote CI disabled, #394).

## Change class → generated docs to resync (in Docker!)

| You changed | Regenerate |
|---|---|
| builtin added/removed, advertisement gate (`lib/CompilerVersion.php`) | `php script/capability-matrix.php` + `php script/generate-pages-capability-comparison.php` |
| unsupported-syntax registry | `php script/capability-syntax.php` |
| anything on `bin/vm.php` require path | `php script/bootstrap-inventory.php` (verify with `--check`) |
| files under `test/bootstrap-aot/` | `php script/bootstrap-profile.php` |
| `composer.json` | refresh `composer.lock` in pinned env; verify `composer validate --no-check-publish` |
| spine (`compiler_lib_spine_smoke/main.php`) | full relink + `make north-star5-verify-fast` |

**Always regenerate inside Docker** (`./script/docker-exec.sh`) — host PHP ≠ pinned PHP 8.2 skews advertised builtins.

## Root-causing drift/regressions

- `git log --oneline -- <path>` to find the commit that introduced drift; name the SHA in issue + PR.
- Red gate on your branch? Check master first: the gate may already be red upstream (see #15619) — fix or note that separately, don't chase ghosts in your diff.

## Winning the merge race at fleet velocity

Master merges every ~10 min; a PR that takes two GitHub round-trips is usually DIRTY by the second one. Protocol:

1. Do ALL drift resolution locally in the worktree you run gates in.
2. On conflict: take `--theirs` for every **generated** file, then regenerate in Docker (never hand-merge generated content); for the spine bundle let git union-merge, then `uniq -d` the require lines — union merges duplicate entries both sides added.
3. `git ls-remote origin <branch> | head -c12` == `git rev-parse HEAD | head -c12` **before** `gh pr create`/`merge` — a silently failed push ships an empty PR.
4. Commit → push → `gh pr create` → `gh pr merge --squash` as ONE uninterrupted sequence.

## Repo-specific gotchas

- `script/apply-patches.sh` after every `composer install` — vendor patches are load-bearing.
- Gate runs regenerate some docs in place; `git checkout docs/` stray changes you didn't intend to commit.
- Only one Docker gate at a time (single CI lock).
- `phpc test --fast` fails on ANY generated-doc drift, not just your change — run `./script/check-generated-docs.sh` first to see drift in seconds.
- Git **worktrees** inside Docker: the `.git` pointer dangles → `git apply` fails for every vendor patch and `patch(1)` misreads git-style headers as file-creation. Mount the parent repo's `.git` read-only at the same path (`-v /path/main-clone/.git:/path/main-clone/.git:ro`) + `git config --global --add safe.directory "*"`.
- Fixing a vendored file? Ship it as a `patches/*.patch` (diffed against the **fully-patched** file, applied last in apply-patches.sh, with an idempotency grep in `patch_already_applied`). Order-changing perf rewrites of the CFG/type pipeline must stay opt-in for lint only — resolution ORDER is observable in AOT codegen even when outputs look identical.
- JIT/LLVM crash or verify failure? `PHP_COMPILER_LLVM_ASSERT=1` first — see phpc-verify's debugging section before reaching for gdb or bisects.
