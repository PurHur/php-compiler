# SessionsWeb scaffold

Project layout from `phpc init --profile sessionsweb` (issue #1886). Application PHP and manifest are kept **byte-identical** to [examples/005-SessionsWeb](../../examples/005-SessionsWeb/) — see sync policy below ([#695](https://github.com/PurHur/php-compiler/issues/695), [#1902](https://github.com/PurHur/php-compiler/issues/1902)).

## Sync policy

| Tree | Role |
|------|------|
| `examples/005-SessionsWeb/` | **Source of truth** (reference app, CI gates, docs) |
| `templates/init-sessionsweb/` | `phpc init --profile sessionsweb` output; must match canonical files |

When you change `example.php`, `phpc.json`, or (post-#1881) routes, handlers, or template partials in the example, copy the same files into this template in the **same PR**.

Verify before merge:

```console
./script/check-init-sessionsweb-parity.sh
```

Intentional divergence (rare): add the same marker in **both** trees:

```php
// sessionsweb-parity: intentional divergence — <reason>
```

## Commands

```console
phpc lint example.php
phpc run example.php
phpc serve 127.0.0.1:8080 .
```

`phpc.json` sets `entry` and the default AOT binary path (`.phpc/bin/app`). VM serve + session flash are green ([#1881](https://github.com/PurHur/php-compiler/issues/1881), [#1887](https://github.com/PurHur/php-compiler/issues/1887)). AOT link/execute: [#1891](https://github.com/PurHur/php-compiler/issues/1891).

## Run matrix

| Mode | Status | Command |
|------|--------|---------|
| Lint | ✅ | `phpc lint example.php` |
| VM run | ✅ | `phpc run example.php` |
| VM serve + flash | ✅ | `phpc serve` + cookie jar (see example README) |
| AOT link/execute | 📋 | [#1891](https://github.com/PurHur/php-compiler/issues/1891) |

See the [php-compiler README](https://github.com/PurHur/php-compiler#quick-start-host-php) for Docker CI and the capability matrix.
