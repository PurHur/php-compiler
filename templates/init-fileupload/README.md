# FileUploadWeb scaffold

Project layout from `phpc init --profile fileupload` (issue #2004). Application PHP and manifest are kept **byte-identical** to [examples/006-FileUploadWeb](../../examples/006-FileUploadWeb/) — see sync policy below ([#695](https://github.com/PurHur/php-compiler/issues/695), [#2020](https://github.com/PurHur/php-compiler/issues/2020)).

## Sync policy

| Tree | Role |
|------|------|
| `examples/006-FileUploadWeb/` | **Source of truth** (reference app, CI gates, docs) |
| `templates/init-fileupload/` | `phpc init --profile fileupload` output; must match canonical files |

When you change `example.php` or `phpc.json` in the example, copy the same files into this template in the **same PR**.

Verify before merge:

```console
./script/check-init-fileupload-parity.sh
```

Intentional divergence (rare): add the same marker in **both** trees:

```php
// fileupload-parity: intentional divergence — <reason>
```

## Commands

```console
phpc lint example.php
phpc run example.php
phpc serve 127.0.0.1:8080 .
curl -s -F 'doc=@README.md' http://127.0.0.1:8080/example.php
```

`phpc.json` sets `entry` and the default AOT binary path (`.phpc/bin/app`). VM serve + multipart POST are green ([#1999](https://github.com/PurHur/php-compiler/issues/1999), [#2009](https://github.com/PurHur/php-compiler/issues/2009)). AOT link/execute: [#2011](https://github.com/PurHur/php-compiler/issues/2011), [#2012](https://github.com/PurHur/php-compiler/issues/2012).

## Run matrix

| Mode | Status | Command |
|------|--------|---------|
| Lint | ✅ | `phpc lint example.php` |
| VM run | ✅ | `phpc run example.php` |
| VM serve + multipart | ✅ | `phpc serve` + `curl -F` (see example README) |
| AOT link/execute | ✅ | `phpc build --project .` |

See the [php-compiler README](https://github.com/PurHur/php-compiler#quick-start-host-php) for Docker CI and the capability matrix.
