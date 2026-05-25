# ApiJson scaffold

Project layout from `phpc init --profile apijson` (issue #2000). Application PHP and manifest are kept **byte-identical** to [examples/004-ApiJson](../../examples/004-ApiJson/) — see sync policy below ([#695](https://github.com/PurHur/php-compiler/issues/695), [#2029](https://github.com/PurHur/php-compiler/issues/2029)).

## Sync policy

| Tree | Role |
|------|------|
| `examples/004-ApiJson/` | **Source of truth** (reference app, CI gates, docs) |
| `templates/init-apijson/` | `phpc init --profile apijson` output; must match canonical files |

When you change `example.php` or `phpc.json` in the example, copy the same files into this template in the **same PR**.

Verify before merge (when **#2029** lands):

```console
./script/check-init-apijson-parity.sh
```

Intentional divergence (rare): add the same marker in **both** trees:

```php
// apijson-parity: intentional divergence — <reason>
```

## Commands

```console
phpc lint example.php
phpc run example.php
phpc serve 127.0.0.1:8080 .
curl -s -D - 'http://127.0.0.1:8080/example.php'
```

`phpc.json` sets `entry` and the default AOT binary path (`.phpc/bin/app`). VM run/serve are green ([#270](https://github.com/PurHur/php-compiler/issues/270)).

## Run matrix

| Mode | Status | Command |
|------|--------|---------|
| Lint | ✅ | `phpc lint example.php` |
| VM run | ✅ | `phpc run example.php` |
| VM serve | ✅ | `phpc serve` + curl (see example README) |
| AOT link/execute | ✅ | `phpc build --project .` |

See the [php-compiler README](https://github.com/PurHur/php-compiler#quick-start-host-php) for Docker CI and the capability matrix.
