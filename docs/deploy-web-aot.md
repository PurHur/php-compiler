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

### `examples/003-MiniWebApp` (deploy layout; AOT link ✅, execute partial)

Manifest includes `public/`, `assets/`, and `templates/` — deploy copies them for nginx/CGI even when native AOT execute lacks VM parity.

```bash
./phpc build --project examples/003-MiniWebApp   # link ✅ ([#752](https://github.com/PurHur/php-compiler/issues/752))
./phpc deploy examples/003-MiniWebApp -o /tmp/miniwebapp-dist
```

**Execute status (master):** Home route `?route=home` emits HTML with `MiniWebApp` title when `PHPC_DEPLOY_ROOT` points at a deploy dist ([#745](https://github.com/PurHur/php-compiler/issues/745)). Hello and contact query routes pass `deploy-smoke.sh --example 003` when `DEPLOY_SMOKE_003_EXECUTE=1` or `MINIWEBAPP_AOT_EXECUTE_GATE=1`. PATH_INFO routes and full VM parity remain tracked in [#676](https://github.com/PurHur/php-compiler/issues/676).

Deploy execute smoke:

```bash
DEPLOY_SMOKE_003_EXECUTE=1 ./script/deploy-smoke.sh --example 003
# or when MINIWEBAPP_AOT_EXECUTE_GATE=1 (default in ci-defaults.env)
```

Manual probe:

```bash
cd examples/003-MiniWebApp
../../phpc build --project .
../../phpc deploy . -o /tmp/miniwebapp-dist
export PHPC_DEPLOY_ROOT=/tmp/miniwebapp-dist
eval "$(../../script/miniwebapp-cgi-env.php --export shellQueryRouteHome)"
export SCRIPT_FILENAME="$PHPC_DEPLOY_ROOT/public/index.php"
export DOCUMENT_ROOT="$PHPC_DEPLOY_ROOT/public"
./bin/app   # from project tree; use $PHPC_DEPLOY_ROOT/bin/app from dist
```

Gate ladder and route matrix: [examples/003-MiniWebApp/README.md](../examples/003-MiniWebApp/README.md), [examples/README.md § 003-MiniWebApp](../examples/README.md#003-miniwebapp).

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

### `003-MiniWebApp` dist tree (typical)

After `phpc deploy examples/003-MiniWebApp -o /var/www/miniwebapp`:

```
$PHPC_DEPLOY_ROOT/
  README.deploy
  bin/app                 # native CGI entry (dynamic HTML routes)
  phpc.json
  public/index.php        # front controller (PATH_INFO + ?route= fallback)
  assets/style.css        # static CSS — serve via nginx, not bin/app
  templates/              # PHP templates — not web-exposed; app reads via includes
```

Implementation: [`lib/Web/ProjectDeploy.php`](../lib/Web/ProjectDeploy.php) copies `public/`, manifest `assets/`, and project `templates/` into the dist. The AOT binary generates HTML for app routes; it does **not** replace a static file server for `/assets/*` in v1 ([#696](https://github.com/PurHur/php-compiler/issues/696)).

Local file-on-disk smoke (no nginx):

```bash
./phpc build --project examples/003-MiniWebApp
./phpc deploy examples/003-MiniWebApp -o /tmp/miniwebapp-dist
test -f /tmp/miniwebapp-dist/assets/style.css
test -f /tmp/miniwebapp-dist/public/index.php
test -f /tmp/miniwebapp-dist/README.deploy
grep -q PHPC_DEPLOY_ROOT /tmp/miniwebapp-dist/README.deploy
```

For HTTP CSS from the native binary, use `phpc serve --aot` (PHPUnit: [#610](https://github.com/PurHur/php-compiler/issues/610), [#478](https://github.com/PurHur/php-compiler/issues/478)). Production nginx should offload static files with `alias` (below).

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

Not exercised in CI — adapt to your host paths and socket. Validate with `nginx -t` before reload.

### Who serves what

| URL prefix | Served by | Notes |
|------------|-----------|-------|
| `/assets/*` | nginx `alias` → dist `assets/` | CSS/JS/images; **not** handled by `bin/app` |
| `/public/*` or docroot files | nginx `root` + `try_files` | Static files under `public/` when present |
| `*.php` / front controller | CGI/FastCGI → `bin/app` or `cgi-wrapper` | Dynamic routes only |
| `templates/` | **not** web-exposed | App reads via PHP includes at runtime |

`phpc serve` (VM) and `phpc serve --aot` serve `/assets/` from the project tree for local dev ([#594](https://github.com/PurHur/php-compiler/issues/594)). AOT deploy dist expects nginx (or another static server) for the same URLs in production.

### Static assets + front controller (`003-MiniWebApp`)

Copy-paste starting point — replace `/var/www/miniwebapp` with your dist path:

```nginx
server {
    listen 80;
    server_name miniwebapp.example.test;

    # Dist root (PHPC_DEPLOY_ROOT) — not the nginx document root
    set $phpc_deploy_root /var/www/miniwebapp;

    root $phpc_deploy_root/public;

    # Static CSS/JS — offload from bin/app (#696)
    location ^~ /assets/ {
        alias $phpc_deploy_root/assets/;
        try_files $uri =404;
        expires 7d;
        add_header Cache-Control "public";
    }

    # Optional: other static files under public/ (favicon, robots.txt)
    location / {
        try_files $uri @app;
    }

    # Front controller: PHP requests → native binary (#489 PATH_INFO)
    location @app {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $phpc_deploy_root/bin/app;
        fastcgi_param PHPC_DEPLOY_ROOT $phpc_deploy_root;
        fastcgi_param DOCUMENT_ROOT $document_root;
        fastcgi_pass unix:/run/fcgiwrap.socket;   # or cgi-wrapper (#665)
    }

    # Alternative: only *.php hits CGI (index.php + PATH_INFO)
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $phpc_deploy_root/bin/app;
        fastcgi_param PHPC_DEPLOY_ROOT $phpc_deploy_root;
        fastcgi_param DOCUMENT_ROOT $document_root;
        fastcgi_pass unix:/run/fcgiwrap.socket;
    }
}
```

Use **either** the `@app` + `try_files` pattern **or** the `location ~ \.php$` block — not both on the same server without adjusting precedence. PATH_INFO must reach `bin/app` for `/index.php/hello` style URLs ([#489](https://github.com/PurHur/php-compiler/issues/489), [#682](https://github.com/PurHur/php-compiler/issues/682)).

Local verify after deploy (file exists; HTTP via nginx is manual):

```bash
curl -sI "http://miniwebapp.example.test/assets/style.css"   # expect 200 once nginx is up
head -1 /var/www/miniwebapp/assets/style.css                  # offline: file on disk
```

### Minimal dist without `public/` (`002-StaticWeb`)

**CGI spawn** (binary as CGI script; no separate static tree):

```nginx
server {
    listen 80;
    server_name static.example.test;
    root /var/www/static-dist;   # dist root; no public/ subtree

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
| Deploy smoke | `make deploy-smoke` or `./script/deploy-smoke.sh` (001/002; 003 execute when `DEPLOY_SMOKE_003_EXECUTE=1` or `MINIWEBAPP_AOT_EXECUTE_GATE=1` — [#718](https://github.com/PurHur/php-compiler/issues/718), [#745](https://github.com/PurHur/php-compiler/issues/745)) |
| Manual deploy | `phpc deploy examples/002-StaticWeb -o /tmp/static-dist` → executable `bin/app` |
| Static assets on disk | `test -f /tmp/miniwebapp-dist/assets/style.css` after 003 deploy ([#696](https://github.com/PurHur/php-compiler/issues/696)) |
| Deploy root env | `grep PHPC_DEPLOY_ROOT /tmp/static-dist/README.deploy` |
| CGI one-shot | `PHPC_DEPLOY_ROOT=/tmp/static-dist QUERY_STRING= ./bin/app` (002 prints HTML) |
| HTTP harness | `make examples-web-smoke` (001, 002, 004; 003 when lint green) |
| Full gate | `./script/ci-local.sh` or `make test` in Docker |

Docker (preferred on harness hosts):

```bash
docker run --rm -v "$(pwd):/compiler" -w /compiler php-compiler:22.04-dev make deploy-smoke
```

Manual one-liner (bind-mount OK on a normal dev machine):

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

## Header injection (VM / JIT / AOT)

User scripts call `header()` to emit CGI response lines. Values containing **CR or LF** are rejected so attackers cannot inject extra headers (issue [#77](https://github.com/PurHur/php-compiler/issues/77)):

- **VM** — `header()` throws `LogicException` before the line is queued.
- **JIT / AOT** — compile-time literals with embedded CR/LF fail at compile time; runtime dynamic values are dropped in `__phpc_pending_header_add` (fail closed).
- **Dev server** — incoming request header values with CR/LF are skipped when mapped to `$_SERVER` (`DevServer::httpHeadersToServerVars`).

Threat model notes for compiled binaries:

- No `eval` in the supported subset; treat user POST/query data as untrusted input.
- Cap body size with `PHP_COMPILER_MAX_BODY` and reverse-proxy limits.
- Do not pass raw user strings to `header()` without validation invariants: `./script/ci-local.sh --filter 'header_reject_crlf|DevServerHeaders'`

## Related issues

| Issue | Topic |
|-------|--------|
| [#445](https://github.com/PurHur/php-compiler/issues/445) | Full production deployment guide |
| [#697](https://github.com/PurHur/php-compiler/issues/697) | MiniWebApp contact POST validation |
| [#77](https://github.com/PurHur/php-compiler/issues/77) | CGI body limits and header sanitization |
| [#50](https://github.com/PurHur/php-compiler/issues/50) | Web runtime / serve |
| [#173](https://github.com/PurHur/php-compiler/issues/173) | FastCGI adapter |
| [#752](https://github.com/PurHur/php-compiler/issues/752) | MiniWebApp AOT link (`phpc build --project`) |
| [#764](https://github.com/PurHur/php-compiler/issues/764) | MiniWebApp AOT execute — empty stdout (closed); VM parity in [#676](https://github.com/PurHur/php-compiler/issues/676) |
| [#676](https://github.com/PurHur/php-compiler/issues/676) | MiniWebApp AOT execute parity / unskip matrix |
| [#623](https://github.com/PurHur/php-compiler/issues/623) | Runtime `include` under deploy root |
| [#612](https://github.com/PurHur/php-compiler/issues/612) | MiniWebApp dist-layout E2E smoke |
| [#696](https://github.com/PurHur/php-compiler/issues/696) | nginx static assets (`alias`) for deploy dist |
| [#610](https://github.com/PurHur/php-compiler/issues/610) | ServeAotTest — assets via `phpc serve --aot` |
| [#478](https://github.com/PurHur/php-compiler/issues/478) | ServeAotTest routes via `phpc serve --aot` |
