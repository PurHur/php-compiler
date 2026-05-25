# ThrowsWeb scaffold

Project layout from `phpc init --profile throwsweb` (issue #2092). Application PHP and manifest are kept **byte-identical** to [examples/007-ThrowsWeb](../../examples/007-ThrowsWeb/) — see sync policy below ([#695](https://github.com/PurHur/php-compiler/issues/695), [#2086](https://github.com/PurHur/php-compiler/issues/2086)).

## Sync policy

| Tree | Role |
|------|------|
| `examples/007-ThrowsWeb/` | **Source of truth** (reference app, CI gates, docs) |
| `templates/init-throwsweb/` | `phpc init --profile throwsweb` output; must match canonical files |

When you change `example.php` or `phpc.json` in the example, copy the same files into this template in the **same PR**.

Verify before merge (opt-in `INIT_THROWSWEB_PARITY_GATE=1` in `ci-fast`, [#2086](https://github.com/PurHur/php-compiler/issues/2086); default-on follow-up [#2127](https://github.com/PurHur/php-compiler/issues/2127)):

```console
./script/check-init-throwsweb-parity.sh
```

Intentional divergence (rare): add the same marker in **both** trees:

```php
// throwsweb-parity: intentional divergence — <reason>
```

## Commands

```console
phpc lint example.php
phpc run example.php
phpc serve 127.0.0.1:8080 .
curl -sf -X POST -d 'email=bad' http://127.0.0.1:8080/example.php | grep -i invalid
```

`phpc.json` sets `entry` and the default AOT binary path (`.phpc/bin/app`). VM serve + caught invalid POST are green ([#2076](https://github.com/PurHur/php-compiler/issues/2076), [#2093](https://github.com/PurHur/php-compiler/issues/2093)). JIT/AOT: [#2101](https://github.com/PurHur/php-compiler/issues/2101).

## Run matrix

| Mode | Status | Command |
|------|--------|---------|
| Lint | ✅ | `phpc lint example.php` |
| VM run | ✅ | `phpc run example.php` |
| VM serve + catch | ✅ | `phpc serve` + POST curl (see example README) |
| AOT link/execute | 📋 | [#2101](https://github.com/PurHur/php-compiler/issues/2101) |

See the [php-compiler README](https://github.com/PurHur/php-compiler#quick-start-host-php) for Docker CI and the capability matrix.
