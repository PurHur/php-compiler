# MiniWebApp scaffold

Project layout from `phpc init --profile miniwebapp` (issue #632). Application PHP, templates, and assets are kept **byte-identical** to [examples/003-MiniWebApp](../../examples/003-MiniWebApp/) — see sync policy below ([#695](https://github.com/PurHur/php-compiler/issues/695)).

## Sync policy

| Tree | Role |
|------|------|
| `examples/003-MiniWebApp/` | **Source of truth** (reference app, CI gates, docs) |
| `templates/init-miniwebapp/` | `phpc init --profile miniwebapp` output; must match canonical files |

When you change routes, `Router.php`, `public/index.php`, `config.php`, `phpc.json`, or template partials in the example, copy the same files into this template in the **same PR**.

Verify before merge (`ci-fast` runs this by default via `INIT_MINIWEBAPP_PARITY_GATE=1`, [#2057](https://github.com/PurHur/php-compiler/issues/2057)):

```console
./script/check-init-miniwebapp-parity.sh
```

Intentional divergence (rare): add the same marker in **both** trees:

```php
// miniwebapp-parity: intentional divergence — <reason>
```

## Commands

```console
phpc lint --all .
phpc serve 127.0.0.1:8080 .
curl -s 'http://127.0.0.1:8080/index.php/hello?name=Dev'
curl -s 'http://127.0.0.1:8080/index.php/home'
phpc build --project .
```

`phpc.json` sets `entry`, `public`, `assets`, `includes`, and the default AOT binary path (`.phpc/bin/app`). Native AOT link and execute are green on `master` ([#752](https://github.com/PurHur/php-compiler/issues/752), [#764](https://github.com/PurHur/php-compiler/issues/764) closed).

## Run matrix

| Mode | Status | Command |
|------|--------|---------|
| Lint | ✅ | `phpc lint --all .` |
| VM serve | ✅ | `phpc serve 127.0.0.1:8080 .` |
| AOT link | ✅ | `phpc build --project .` when LLVM ready |
| AOT execute | ✅ | native home/hello/contact routes ([#764](https://github.com/PurHur/php-compiler/issues/764) closed) |
| Deploy smoke | opt-in | `DEPLOY_SMOKE_003_EXECUTE=1` in php-compiler `deploy-smoke.sh` ([#1530](https://github.com/PurHur/php-compiler/issues/1530)) |

## CI gate ladder

Progressive checks for this layout are tracked in [issue #472](https://github.com/PurHur/php-compiler/issues/472) (`script/miniwebapp-gates.sh` in the php-compiler repo). Full ladder table: [docs/miniwebapp-gates.md](https://github.com/PurHur/php-compiler/blob/master/docs/miniwebapp-gates.md).

| Stage | Check | Status |
|-------|--------|--------|
| 1 | `phpc lint --all` | ✅ |
| 2 | `ServeTest` `@group miniwebapp` | ✅ |
| 4b | AOT link (`phpc build --project`) | ✅ |
| 4b2 | AOT execute (CGI env) | ✅ ([#764](https://github.com/PurHur/php-compiler/issues/764) closed) |
| 4d | `deploy-smoke --example 003` | opt-in ([#1530](https://github.com/PurHur/php-compiler/issues/1530)) |

See the [php-compiler README](https://github.com/PurHur/php-compiler#quick-start-host-php) for Docker CI and the capability matrix.
