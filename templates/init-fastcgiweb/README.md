# FastCGIWeb scaffold

Project layout from `phpc init --profile fastcgiweb` (issue #2342). Application PHP and manifest are kept **byte-identical** to [examples/009-FastCGIWeb](../../examples/009-FastCGIWeb/) — see sync policy below ([#695](https://github.com/PurHur/php-compiler/issues/695)).

## Sync policy

| Tree | Role |
|------|------|
| `examples/009-FastCGIWeb/` | **Source of truth** (FastCGI / deploy presenter, CI gates, docs) |
| `templates/init-fastcgiweb/` | `phpc init --profile fastcgiweb` output; must match canonical files |

When you change `example.php` or `phpc.json` in the example, copy the same files into this template in the **same PR**.

Verify before merge (`ci-fast` runs this when `INIT_FASTCGIWEB_PARITY_GATE=1`, [#2342](https://github.com/PurHur/php-compiler/issues/2342)):

```console
./script/check-init-fastcgiweb-parity.sh
```

Intentional divergence (rare): add the same marker in **both** trees:

```php
// fastcgiweb-parity: intentional divergence — <reason>
```

## Commands

```console
phpc lint example.php
phpc run example.php
phpc serve 127.0.0.1:8080 .
curl -s http://127.0.0.1:8080/example.php
curl -s http://127.0.0.1:8080/example.php/ping
```

`phpc.json` sets `entry` and the default AOT binary path (`.phpc/bin/app`). Health + PATH_INFO diagnostics via CGI superglobals ([#2331](https://github.com/PurHur/php-compiler/issues/2331)).

## Run matrix

| Mode | Status | Command |
|------|--------|---------|
| Lint | ✅ | `phpc lint example.php` |
| VM run | ✅ | `phpc run example.php` |
| VM serve | ✅ | `phpc serve 127.0.0.1:8080 .` |
| AOT / deploy | ✅ | `phpc build --project .` · `phpc deploy` ([#635](https://github.com/PurHur/php-compiler/issues/635)) |

See [examples/009-FastCGIWeb/README.md](../../examples/009-FastCGIWeb/README.md) and [docs/deploy-web-aot.md](../../docs/deploy-web-aot.md).
