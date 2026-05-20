# php-compiler web app

Minimal project scaffold from `phpc init`.

## Commands

```console
phpc lint public/index.php
phpc run public/index.php
phpc run -q 'name=Dev' public/index.php
phpc serve .
phpc build -o .phpc/bin/app public/index.php
phpc serve --aot .
```

`phpc.json` sets `entry` and the default AOT binary path (`.phpc/bin/app`).

See the [php-compiler README](https://github.com/PurHur/php-compiler#quick-start-host-php) for Docker CI and the full capability matrix.
