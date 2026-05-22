# MiniWebApp scaffold

Project layout from `phpc init --profile miniwebapp` (issue #632). For the full reference app see [examples/003-MiniWebApp](../../examples/003-MiniWebApp/).

## Commands

```console
phpc lint --all .
phpc serve 127.0.0.1:8080 .
curl -s 'http://127.0.0.1:8080/index.php?route=home'
curl -s 'http://127.0.0.1:8080/index.php/home'
phpc build --project .
```

`phpc.json` sets `entry`, `public`, `assets`, `includes`, and the default AOT binary path (`.phpc/bin/app`).

## CI gate ladder

Progressive checks for this layout are tracked in [issue #472](https://github.com/PurHur/php-compiler/issues/472) (`script/miniwebapp-gates.sh`).

See the [php-compiler README](https://github.com/PurHur/php-compiler#quick-start-host-php) for Docker CI and the capability matrix.
