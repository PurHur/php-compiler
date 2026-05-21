# php-compiler web app

Minimal project scaffold from `phpc init`.

## Commands

```console
phpc lint public/index.php
phpc run public/index.php
phpc run -q 'name=Dev' public/index.php
phpc serve 127.0.0.1:8080 .
phpc build -o .phpc/bin/app public/index.php
phpc serve --aot 127.0.0.1:8080 .
```

`phpc serve` from the project root uses `phpc.json` `"public"` as the HTTP document root (only files under `public/` are served).

`phpc.json` sets `entry`, `public`, and the default AOT binary path (`.phpc/bin/app`).

See the [php-compiler README](https://github.com/PurHur/php-compiler#quick-start-host-php) for Docker CI and the full capability matrix.
