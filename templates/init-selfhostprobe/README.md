# SelfHostProbe scaffold

Project layout from `phpc init --profile selfhostprobe` (issue #2220). Application PHP and manifest are kept **byte-identical** to [examples/008-SelfHostProbe](../../examples/008-SelfHostProbe/) — see sync policy below ([#695](https://github.com/PurHur/php-compiler/issues/695)).

## Sync policy

| Tree | Role |
|------|------|
| `examples/008-SelfHostProbe/` | **Source of truth** (North Star 2 presenter, CI gates, docs) |
| `templates/init-selfhostprobe/` | `phpc init --profile selfhostprobe` output; must match canonical files |

When you change `example.php` or `phpc.json` in the example, copy the same files into this template in the **same PR**.

Verify before merge (`ci-fast` runs this by default via `INIT_SELFHOSTPROBE_PARITY_GATE=1`, [#2220](https://github.com/PurHur/php-compiler/issues/2220)):

```console
./script/check-init-selfhostprobe-parity.sh
```

Intentional divergence (rare): add the same marker in **both** trees:

```php
// selfhostprobe-parity: intentional divergence — <reason>
```

## Commands

```console
phpc lint example.php
phpc run example.php
make north-star2-verify
```

`phpc.json` sets `entry` and the default AOT binary path (`.phpc/bin/app`). VM presenter text only — no superglobals ([#2207](https://github.com/PurHur/php-compiler/issues/2207)).

## Run matrix

| Mode | Status | Command |
|------|--------|---------|
| Lint | ✅ | `phpc lint example.php` |
| VM run | ✅ | `phpc run example.php` |
| VM serve | 📋 | not used (CLI presenter) |
| AOT | 📋 | optional later — out of scope v1 |

See [docs/bootstrap-selfhost.md](../../docs/bootstrap-selfhost.md) and the [php-compiler README](https://github.com/PurHur/php-compiler#quick-start-host-php) for Docker CI.
