# Contributing

## Before you open an issue or pull request

**We do not accept issues or pull requests on this repository without prior coordination.**

If you want to contribute, **write to the maintainers on other channels first** — not via a drive-by GitHub issue or pull request here. **Do not waste your time** preparing changes on this repo until scope and approach are agreed.

Development moves quickly and is largely coordinated through **AI agents** and maintainer workflows. You are still free to **fork** and experiment under the MIT license, but at this pace **you are unlikely to make progress on upstream** unless you **align with the AI-agent workflow first**; otherwise your work may diverge or be superseded before it can land.

This policy applies to **issues and pull requests on `PurHur/php-compiler`**. Forks and private experiments are fine.

Please follow [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md) in all project interactions.

---

## If you are already aligned

The sections below are for **maintainers and collaborators who have already coordinated** on other channels.

### Pull request process

All submissions, including by project members, require review via GitHub pull requests. See [GitHub Help](https://help.github.com/articles/about-pull-requests/) for using pull requests.

**Generated docs** (run locally before push; see [docs/bootstrap-selfhost.md](docs/bootstrap-selfhost.md)):

- `php script/bootstrap-inventory.php` when the `bin/vm.php` dependency path changes
- `php script/bootstrap-selfhost-compile-probe.php --update-inventory` writes `docs/bootstrap-inventory-live-probe.md` (not `docs/bootstrap-inventory.md`; #2891); run `php script/bootstrap-inventory.php` if inventory headers drift
- `php script/capability-matrix.php` / `php script/capability-syntax.php` when builtins or unsupported-syntax registry change

Also run `php script/bootstrap-inventory.php --check` before push when bootstrap paths change.

### Verifying your change

Merge gates are **local/Docker only** — GitHub Actions and CircleCI are disabled ([#394](https://github.com/PurHur/php-compiler/issues/394)); optional mirrors live under [`.github/workflows-disabled/`](.github/workflows-disabled/). See the full matrix in [docs/local-ci-matrix.md](docs/local-ci-matrix.md).

#### Host PHP available

From the repo root (after `composer install` and `script/apply-patches.sh`):

- **Iteration:** `./script/ci-fast.sh`
- **Pre-merge:** `./script/ci-local.sh`
- **Targeted:** `vendor/bin/phpunit --filter VMTest` (or append `--filter` to `ci-fast.sh` / `ci-local.sh`)

The first `ci-local.sh` run downloads LLVM 9 into `.llvm/` (`script/install-llvm9.sh`). See [README.md](README.md) Quick start (host PHP).

#### Docker-only / harness (Runforge)

On hosts **without** system PHP/LLVM, or on Runforge/harness sandboxes where `docker run -v "$(pwd):/compiler"` often mounts an **empty** tree:

- **Full gate:** `make test-harness` or `./script/docker-ci-local.sh`
- **Fast iteration inside Docker:** `./script/docker-exec.sh -- bash -lc 'source script/php-env.sh && ./script/ci-fast.sh'`
- **Targeted:** `./script/docker-exec.sh -- bash -lc 'source script/php-env.sh && vendor/bin/phpunit --filter VMTest'`

Do **not** use raw `docker run -v "$(pwd):/compiler"` — use the wrappers above ([#245](https://github.com/PurHur/php-compiler/issues/245), [#2245](https://github.com/PurHur/php-compiler/issues/2245)).

On Runforge harness agents, `$HARNESS_DOCKER_RUN_OPTS` is set by the environment (passed through by `script/ci-docker-run.sh`); no manual export needed.

More: [README.md § Quick start (Docker)](README.md#quick-start-docker-only) · [docs/GETTING-STARTED.md § Troubleshooting](docs/GETTING-STARTED.md#troubleshooting)

**Public documentation** (when a user-visible milestone lands):

- Update [`docs/pages/development-status.md`](docs/pages/development-status.md) (authoritative status for [GitHub Pages](https://purhur.github.io/php-compiler/development-status.html)) — **do not link** capability/inventory/CI matrices from public pages.
- Sync north-star / “what works” tables in [`README.md`](README.md) and, if needed, [`docs/pages/index.html`](docs/pages/index.html) progress badges.
- Regenerate contributor matrices (`capabilities.md`, etc.) when builtins change — repo-only, not part of the public site.
- See [`docs/pages/PAGES.md`](docs/pages/PAGES.md) for publish workflow and excluded map docs.

1. Ensure any install or build dependencies are removed before the end of the layer when doing a build.
2. Update the [README.md](README.md) with details of changes to the interface, this includes new environment variables, exposed ports, useful file locations and container parameters (if applicable).
3. Increase the version numbers in any examples files and the README.md to the new version that this Pull Request would represent. The versioning scheme we use is [SemVer](http://semver.org/).
4. You may merge the Pull Request in once you have the sign-off of other developers, or if you do not have permission to do that, you may request the second reviewer to merge it for you.
