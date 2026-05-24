# CircleCI (temporarily disabled)

No `config.yml` is present in this directory, so CircleCI does not run a pipeline for this repo.

The previous configuration is kept at [../circleci-disabled/config.yml](../circleci-disabled/config.yml).

## Local verification

```bash
./script/ci-local.sh
# or:
make docker-build-clean && make build && make phan && make test
```

Docker image: `php-compiler:22.04-dev` (`make docker-build-22`).

## Re-enable

Restore `config.yml` from `.circleci-disabled/` and re-enable the project in the CircleCI UI if needed.
