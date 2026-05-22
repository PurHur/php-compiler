# Deploy web apps with AOT (quickstart)

Short path from **`phpc build --project`** → **`phpc deploy`** → **`PHPC_DEPLOY_ROOT`** → nginx/CGI in front of a native binary. For the full production guide (TLS, hardening, FastCGI pools), see [#445](https://github.com/PurHur/php-compiler/issues/445). Deploy CLI landed in [#609](https://github.com/PurHur/php-compiler/issues/609) (supersedes [#180](https://github.com/PurHur/php-compiler/issues/180)).

## Prerequisites

- Built dev image: `make docker-build-22` → `php-compiler:22.04-dev`
- LLVM 9 inside the image (default) for `phpc build --project`
- On Runforge/harness hosts with an empty bind-mount, pipe the repo: `./script/docker-ci-local.sh` (see [local-ci-matrix.md](local-ci-matrix.md))

All commands below assume repository root and `./phpc` (wrapper around `bin/phpc.php`).

## 1. Build and deploy

### `examples/002-StaticWeb` (AOT green today)

Minimal HTML page with no `public/` tree — good for a first dist smoke.

```bash
./phpc build --project examples/002-StaticWeb
./phpc deploy examples/002-StaticWeb -o /tmp/static-dist
```

If the binary is missing, `phpc deploy` tells you to build first; use `phpc deploy … --from-build` only when your workflow builds in a separate step and you pass a prebuilt `.phpc/bin/app`.

### `examples/003-MiniWebApp` (deploy layout; native binary blocked)

Manifest includes `public/`, `assets/`, and `templates/` — deploy copies them even when the AOT link is not production-ready.

```bash
./phpc build --project examples/003-MiniWebApp   # may fail until user-class AOT [#568](https://github.com/PurHur/php-compiler/issues/568)
./phpc deploy examples/003-MiniWebApp -o /tmp/miniwebapp-dist
```

Until [#568](https://github.com/PurHur/php-compiler/issues/568) lands, treat MiniWebApp as **VM + `phpc serve`** for runtime checks; use deploy only to validate the **dist layout** and `README.deploy` after a local build succeeds.

Implementation: [`lib/Web/ProjectDeploy.php`](../lib/Web/ProjectDeploy.php).

## 2. Dist layout

After `phpc deploy -o <dist>`:

| Path | Role |
|------|------|
| `bin/app` | AOT executable (from `phpc.json` `"binary"`, usually `.phpc/bin/app`) |
| `phpc.json` | Project manifest (entry, public, assets, includes) |
| `public/` | Document root for static files and front controller (when manifest sets `"public"`) |
| `assets/` | Static assets referenced by the app (when manifest sets `"assets"`) |
| `templates/` | PHP templates copied from project `templates/` (MiniWebApp) |
| `README.deploy` | Operator notes: `PHPC_DEPLOY_ROOT`, CGI env, debug flag |

`002-StaticWeb` dist is typically `bin/app`, `phpc.json`, and `README.deploy` only. `003-MiniWebApp` adds `public/`, `assets/`, and `templates/`.

## 3. `PHPC_DEPLOY_ROOT`

Set to the **absolute path of the dist directory** before running `bin/app`. Required when the binary uses `phpc_deploy_path()` or runtime includes under the deploy tree ([#585](https://github.com/PurHur/php-compiler/issues/585), runtime include follow-up [#623](https://github.com/PurHur/php-compiler/issues/623)).

```bash
export PHPC_DEPLOY_ROOT=/tmp/static-dist
cd /tmp/static-dist
./bin/app
```

For CGI-style superglobals per request (instead of values baked at link time with `phpc build -q`):

```bash
export PHPC_DEPLOY_ROOT=/tmp/static-dist
export QUERY_STRING='name=Dev'
export REQUEST_METHOD=GET
./bin/app
```

`README.deploy` in the dist repeats these variables.

## 4. nginx (illustrative)

Not exercised in CI — adapt to your host paths and socket.

**CGI spawn** (binary as CGI script; docroot for static files when `public/` exists):

```nginx
server {
    listen 80;
    server_name static.example.test;
    root /var/www/static-dist/public;   # omit or use dist root if no public/

    location / {
        try_files $uri @app;
    }

    location @app {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /var/www/static-dist/bin/app;
        fastcgi_param PHPC_DEPLOY_ROOT /var/www/static-dist;
        fastcgi_pass unix:/run/fcgiwrap.socket;   # or your CGI bridge
    }
}
```

**FastCGI / php-fpm-style** long-lived adapter is tracked in [#173](https://github.com/PurHur/php-compiler/issues/173). Until then, `phpc serve --aot` is the local loopback harness ([#50](https://github.com/PurHur/php-compiler/issues/50) web runtime).

Production AOT CGI wrapper for nginx spawn: [#665](https://github.com/PurHur/php-compiler/issues/665).

## 5. Local verify checklist

| Step | Command |
|------|---------|
| Deploy smoke | `phpc deploy examples/002-StaticWeb -o /tmp/static-dist` → executable `bin/app` |
| Deploy root env | `grep PHPC_DEPLOY_ROOT /tmp/static-dist/README.deploy` |
| CGI one-shot | `PHPC_DEPLOY_ROOT=/tmp/static-dist QUERY_STRING= ./bin/app` (002 prints HTML) |
| HTTP harness | `make examples-web-smoke` (001, 002, 004; 003 when lint green) |
| Full gate | `./script/ci-local.sh` or `make test` in Docker |

Docker one-liner (bind-mount OK on a normal dev machine):

```bash
docker run --rm -v "$(pwd):/compiler" -w /compiler php-compiler:22.04-dev bash -lc '
  ./phpc build --project examples/002-StaticWeb
  ./phpc deploy examples/002-StaticWeb -o /tmp/static-dist
  test -x /tmp/static-dist/bin/app
  grep PHPC_DEPLOY_ROOT /tmp/static-dist/README.deploy
'
```

On harness hosts with an empty mount, use:

```bash
./script/docker-ci-local.sh fast --filter PhpcDeployTest
```

## Request body limits (VM / `phpc serve`)

`phpc serve` and `bin/cgi.php` cap decoded POST bodies at **8 MiB** by default (`DevServer::MAX_REQUEST_BODY`, issue [#77](https://github.com/PurHur/php-compiler/issues/77)). Oversized `Content-Length` values are rejected with **HTTP 413** before the script runs.

Operators may lower the cap for dev or edge deploys:

```bash
export PHP_COMPILER_MAX_BODY=65536   # bytes; capped at 8 MiB
./phpc serve 127.0.0.1:8080 examples/003-MiniWebApp
```

`examples/003-MiniWebApp` validates the contact `name` field (non-empty, max 200 chars, configurable via `config.php` `contact_name_max`) and returns **400** with plain text on invalid input ([#697](https://github.com/PurHur/php-compiler/issues/697)). Put nginx `client_max_body_size` in front of the app for production hardening ([#445](https://github.com/PurHur/php-compiler/issues/445)).

## Related issues

| Issue | Topic |
|-------|--------|
| [#445](https://github.com/PurHur/php-compiler/issues/445) | Full production deployment guide |
| [#697](https://github.com/PurHur/php-compiler/issues/697) | MiniWebApp contact POST validation |
| [#77](https://github.com/PurHur/php-compiler/issues/77) | CGI body limits and header sanitization |
| [#50](https://github.com/PurHur/php-compiler/issues/50) | Web runtime / serve |
| [#173](https://github.com/PurHur/php-compiler/issues/173) | FastCGI adapter |
| [#568](https://github.com/PurHur/php-compiler/issues/568) | User-class AOT for MiniWebApp binary |
| [#623](https://github.com/PurHur/php-compiler/issues/623) | Runtime `include` under deploy root |
| [#612](https://github.com/PurHur/php-compiler/issues/612) | MiniWebApp dist-layout E2E smoke |
