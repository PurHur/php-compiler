---
name: phpc-setup
description: Bootstrap a working php-compiler dev environment (host or Docker) from a fresh clone — composer, patches, pinned LLVM 9 image — and verify it with a smoke run. Use at session start when the repo is not yet set up, when vendor/ is missing, or when gates fail with environment-shaped errors.
---

# php-compiler environment setup

## Decide host vs Docker first

| You need | Environment |
|---|---|
| VM tests, PHPUnit, doc regen checks (Tier 0) | Host PHP 8.1+ works; **pinned results only in Docker** |
| AOT/JIT (`phpc build`, LLVM), self-host gates (Tier 1–2) | **Docker** `php-compiler:22.04-dev` (LLVM 9 at `/opt/llvm9`) |
| Regenerating committed generated docs | **Docker only** — host PHP version skews builtin advertisement |

The pinned reference env is PHP 8.2 inside `php-compiler:22.04-dev`. Host PHP 8.3+ requires `--ignore-platform-reqs` and may produce doc-regen diffs that must NOT be committed.

## Fresh-clone bootstrap (once per clone)

```bash
git clone https://github.com/PurHur/php-compiler && cd php-compiler
make docker-build-22                       # builds php-compiler:22.04-dev (cached after first time)
composer install --ignore-platform-reqs   # host vendor/ (locked deps target PHP 8.1–8.2)
script/apply-patches.sh                    # mandatory; idempotent ("Skip ... already applied" is normal)
```

Never run raw `docker run -v "$(pwd):/compiler"` — always `./script/docker-exec.sh -- bash -lc '<cmd>'` (handles tar fallback, CI lock, memory caps; #245, #2245).

## Smoke-verify the env

```bash
./script/docker-exec.sh -- bash -lc 'php bin/vm.php examples/000-HelloWorld/example.php'   # pinned VM: "Hello World"
./script/docker-exec.sh -- bash -lc \
  './phpc build -o /tmp/hello examples/000-HelloWorld/example.php && /tmp/hello'   # Docker AOT
```

Both green → environment is good. `./phpc doctor` diagnoses further. On RunForge / agent-harness, **never** use bare host `php bin/vm.php` — always `docker-exec.sh`.

## Fast cold starts (committed caches)

- `prelinked/bootstrap-gen0/` — gen-0 self-host driver + sidecars (no arch key; x86_64-linux implied).
- `prelinked/helper-runtime/<uname m>-<uname s>/` — split-compilation helper objects; consume with `PHP_COMPILER_HELPER_RUNTIME_O=1` (see phpc-helper-cache skill). A fresh clone on a matching arch skips the ~6-min helper corpus compile entirely; other arches fall back to compiling locally.
- `build/lint-cache/` — per-file inventory-lint results (auto; warm full-inventory lint ≈ 0.5 s).

## Known traps

- **vendor/ missing inside Docker** → docker-exec tar-copies the repo; changes to the copied tree do NOT land in your checkout. Install vendor on host first so the bind-mount is used.
- **Gate runs mutate generated docs in place** (`ci_ensure_generated_doc`): after any Docker gate run, `git status` may show modified `docs/bootstrap-inventory.md` / `docs/bootstrap-profile.json`. Only commit these when master actually drifted; otherwise `git checkout docs/`.
- **Single CI lock**: only one docker-exec/gate at a time per host.
- Long gates: run in background and poll the log; don't block a session on `--strict` (~1 h).
- **Git worktrees + raw docker mounts**: the worktree's `.git` pointer file dangles inside the container — `git apply` (and therefore apply-patches) silently degrades. Mount the main clone's `.git` read-only at the SAME absolute path and `git config --global --add safe.directory "*"`, or work in the main clone.
- **Multiple worktrees on one host**: the shell's cwd persists between commands — always use absolute paths for edits and mounts, and verify `docker inspect <container> --format '{{range .Mounts}}{{.Source}}{{end}}'` matches the tree you think you're testing.
