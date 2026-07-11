# GitHub Actions

## Active workflows

| Workflow | Trigger | Purpose |
|----------|---------|---------|
| [**bootstrap-spine-gate.yml**](bootstrap-spine-gate.yml) | PR/push on spine, gen-0, inventory paths; manual | Fast bootstrap PR gate: inventory (`--check-spine`), spine coverage, **sidecar stamp sync**, `north-star5-verify-fast` |
| [**github-pages.yml**](github-pages.yml) | Push when `docs/pages/**` changes; manual | Jekyll site at `docs/pages` (not every master merge) |
| | `workflow_dispatch` + `run_bootstrap_loop=true` | Optional `bootstrap-loop-probe` (~9 min) |

Contributor guide: [docs/bootstrap-dev-workflow.md](../docs/bootstrap-dev-workflow.md).

**Spine PR rule:** If you edit `test/selfhost/compiler_lib_spine_smoke/main.php`, run `make bootstrap-gen0-refresh-sidecar` and commit `prelinked/bootstrap-gen0/` in the same PR (or follow up immediately). CI fails when `check-selfhost-spine-sidecar-sync.php` sees a stale stamp.

## Disabled workflows

Moved to [workflows-disabled/](../workflows-disabled/) while broader CI is paused ([#394](https://github.com/PurHur/php-compiler/issues/394)):

| Workflow | Location |
|----------|----------|
| Bootstrap self-host (legacy) | [bootstrap-selfhost.yml](../workflows-disabled/bootstrap-selfhost.yml) |

**GitHub Pages** uses [github-pages.yml](github-pages.yml) (workflow deploy, `docs/pages/` only). Repo Settings → Pages must use **GitHub Actions** as the source (not legacy `/` root).

## Local verification (same gates)

```bash
make docker-build-22
./script/docker-exec.sh -- bash -lc '
  composer install --ignore-platform-reqs -q && script/apply-patches.sh
  php script/check-selfhost-spine-coverage-sync.php
  php script/check-selfhost-spine-sidecar-sync.php
  make north-star5-verify-fast
'
```

Full ladder: `./script/docker-exec.sh -- bash -lc './script/bootstrap-loop-probe.sh'`

CircleCI is also disabled; see [`.circleci/README.md`](../.circleci/README.md).
