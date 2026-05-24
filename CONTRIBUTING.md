# How to Contribute

We'd love to accept your patches and contributions to this project. There are just a few small guidelines you need to follow.

When contributing to this repository, please first discuss that new features be discussed via issue before making a change.

Please report any bugs to the issues page, due to the fact this project is done in free time, it may take a while to process the issues, but security vulnerabilities will have precedence over feature requests.

Please note we have a code of conduct, [CODE\_OF\_CONDUCT.md](CODE_OF_CONDUCT.md), please follow it in all your interactions with the project.

# Pull Request Process

All submissions, including submissions by project members, require review. We use GitHub pull requests for this purpose. Consult
[GitHub Help](https://help.github.com/articles/about-pull-requests/) for more information on using pull requests.

**Generated docs** (run locally before push; see [docs/bootstrap-selfhost.md](docs/bootstrap-selfhost.md)):

- `php script/bootstrap-inventory.php` when the `bin/vm.php` dependency path changes
- `php script/bootstrap-selfhost-compile-probe.php --update-inventory` when the live self-host probe section changes, then `php script/bootstrap-inventory.php` if needed
- `php script/capability-matrix.php` / `php script/capability-syntax.php` when builtins or unsupported-syntax registry change

Verify: `php script/bootstrap-inventory.php --check` and `./script/ci-local.sh` (or `./script/ci-fast.sh` while iterating).

**Public documentation** (when a user-visible milestone lands):

- Update [`docs/pages/development-status.md`](docs/pages/development-status.md) (authoritative status for [GitHub Pages](https://purhur.github.io/php-compiler/development-status.html)).
- Sync north-star / “what works” tables in [`README.md`](README.md) and, if needed, [`docs/pages/index.html`](docs/pages/index.html) progress badges.
- See [`docs/pages/PAGES.md`](docs/pages/PAGES.md) for publish workflow.

1. Ensure any install or build dependencies are removed before the end of the layer when doing a build.
2. Update the [README.md](README.md) with details of changes to the interface, this includes new environment variables, exposed ports, useful file locations and container parameters (if applicable).
3. Increase the version numbers in any examples files and the README.md to the new version that this Pull Request would represent. The versioning scheme we use is [SemVer](http://semver.org/).
4. You may merge the Pull Request in once you have the sign-off of other developers, or if you do not have permission to do that, you may request the second reviewer to merge it for you.