# MiniWebApp scaffold

Project layout from `phpc init --profile miniwebapp` (issue #632). Application PHP, templates, and assets are kept **byte-identical** to [examples/003-MiniWebApp](../../examples/003-MiniWebApp/) — see sync policy below ([#695](https://github.com/PurHur/php-compiler/issues/695)).

## Sync policy

| Tree | Role |
|------|------|
| `examples/003-MiniWebApp/` | **Source of truth** (reference app, CI gates, docs) |
| `templates/init-miniwebapp/` | `phpc init --profile miniwebapp` output; must match canonical files |

When you change routes, `Router.php`, `public/index.php`, `config.php`, `phpc.json`, or template partials in the example, copy the same files into this template in the **same PR**.

Verify before merge:

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

`phpc.json` sets `entry`, `public`, `assets`, `includes`, and the default AOT binary path (`.phpc/bin/app`). Native AOT for user classes is tracked in [#568](https://github.com/PurHur/php-compiler/issues/568).

## CI gate ladder

Progressive checks for this layout are tracked in [issue #472](https://github.com/PurHur/php-compiler/issues/472) (`script/miniwebapp-gates.sh`).

See the [php-compiler README](https://github.com/PurHur/php-compiler#quick-start-host-php) for Docker CI and the capability matrix.
