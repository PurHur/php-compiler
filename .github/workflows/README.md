# GitHub Actions (temporarily disabled)

Workflows are **not** run from this directory while CI is paused.

| Previous workflow | Location |
|-------------------|----------|
| Bootstrap self-host | [../workflows-disabled/bootstrap-selfhost.yml](../workflows-disabled/bootstrap-selfhost.yml) |
| GitHub Pages | [../workflows-disabled/github-pages.yml](../workflows-disabled/github-pages.yml) |

## Local verification

Use the same gates locally (Docker image `php-compiler:22.04-dev`):

```bash
./script/ci-local.sh
# or bootstrap-only:
make bootstrap-selfhost-probe
./script/bootstrap-selfhost-link.sh
./script/bootstrap-wave-check.sh --with-compile-smoke --fail-fast
```

CircleCI is also disabled; see [`.circleci/README.md`](../.circleci/README.md).

## Re-enable

Move the YAML files back into `.github/workflows/` and remove this README (or leave a one-line pointer).
