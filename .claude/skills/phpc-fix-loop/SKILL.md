---
name: phpc-fix-loop
description: The smallest-gate-first edit→verify→PR loop for a php-compiler code change, including which generated docs to resync for which change class and the PR/merge protocol. Use when implementing any fix or feature in this repo.
---

# php-compiler fix loop

## Loop shape (fast feedback first)

1. **Repro first**: write/locate a minimal repro under `test/repro/` (or a failing `--filter` PHPUnit case). Run it on the VM: `php bin/vm.php test/repro/<x>.php`. Seconds.
2. **Edit** the narrowest layer: prefer PHP lowering (`lib/`) / module registration (`ext/<mod>/`) over C runtime branches.
3. **Verify inner loop**: re-run the repro + targeted `vendor/bin/phpunit --filter <Class>`. Iterate here — never iterate on full gates.
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

## Repo-specific gotchas

- `script/apply-patches.sh` after every `composer install` — vendor patches are load-bearing.
- Gate runs regenerate some docs in place; `git checkout docs/` stray changes you didn't intend to commit.
- Only one Docker gate at a time (single CI lock).
- `phpc test --fast` fails on ANY generated-doc drift, not just your change — run `./script/check-generated-docs.sh` first to see drift in seconds.
