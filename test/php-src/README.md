# php-src PHPT corpus (#36381)

Run php-src's own `.phpt` files under Zend / VM / AOT and gate on **failing-name set
difference** (same honesty rule as `compliance-baseline.sh` — AGENTS.md §2).

## Harness self-test (no php-src checkout)

```bash
./script/docker-exec.sh -- bash -lc 'source script/php-env.sh && script/php-src/php-src-phpt.sh --corpus=sample --backend=vm'
./script/docker-exec.sh -- bash -lc 'source script/php-env.sh && script/php-src/php-src-phpt.sh --corpus=sample --backend=vm --diff'
```

`test/php-src/corpus/sample/` is a tiny authored corpus shaped like php-src PHPT
(TEST/FILE/EXPECT/EXPECTF/SKIPIF). It is **not** a dump of php-src.

Runner lives under `script/php-src/` (subdir) so the top-level `script/*.{sh,php}`
file-count budget (#36403) does not grow.

## Full php-src tree

```bash
git clone --depth 1 --branch php-8.2.28 https://github.com/php/php-src.git /tmp/php-src
script/php-src/php-src-phpt.sh \
  --php-src=/tmp/php-src \
  --dirs=Zend/tests \
  --backend=vm \
  --shards=24 --shard=0 \
  --collect
```

Baselines land in `test/php-src/baselines/<label>-<backend>.{failing,executed,skipped}`.

## Scoreboard

```bash
script/php-src/php-src-phpt.sh --corpus=sample --backend=vm --scoreboard
# writes docs/pages/php-src.html
```
