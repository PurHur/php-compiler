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
php bin/vm.php examples/000-HelloWorld/example.php                      # host VM: "Hello World"
./script/docker-exec.sh -- bash -lc \
  './phpc build -o /tmp/hello examples/000-HelloWorld/example.php && /tmp/hello'   # Docker AOT
```

Both green → environment is good. `./phpc doctor` diagnoses further.

## Known traps

- **vendor/ missing inside Docker** → docker-exec tar-copies the repo; changes to the copied tree do NOT land in your checkout. Install vendor on host first so the bind-mount is used.
- **Gate runs mutate generated docs in place** (`ci_ensure_generated_doc`): after any Docker gate run, `git status` may show modified `docs/bootstrap-inventory.md` / `docs/bootstrap-profile.json`. Only commit these when master actually drifted; otherwise `git checkout docs/`.
- **Single CI lock**: only one docker-exec/gate at a time per host.
- Long gates: run in background and poll the log; don't block a session on `--strict` (~1 h).
