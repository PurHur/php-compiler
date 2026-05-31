# `phpc.json` project manifest

Shipped web examples and `phpc init` scaffolds place a **`phpc.json`** beside the project root. The compiler CLI reads it for `phpc build --project`, `phpc serve`, `phpc deploy`, and `phpc serve --aot`.

Implementation: [`lib/Web/ProjectManifest.php`](../lib/Web/ProjectManifest.php). Validation: [`lib/Web/ManifestValidator.php`](../lib/Web/ManifestValidator.php) via `phpc validate-manifest`.

## Field reference

Paths are relative to the directory that contains `phpc.json` (unless they start with `/`).

| Field | Required | Type | Purpose |
|-------|----------|------|---------|
| `entry` | yes (build) | string | Script compiled by `phpc build --project` / `--project` |
| `binary` | yes (validate) | string | Default AOT output path; `phpc serve --aot` resolves this when the file exists |
| `public` | no | string | HTTP document root for `phpc serve` / deploy ([#443](https://github.com/PurHur/php-compiler/issues/443), [#609](https://github.com/PurHur/php-compiler/issues/609)) |
| `assets` | no | string | Static files directory copied on `phpc deploy` ([#594](https://github.com/PurHur/php-compiler/issues/594)) |
| `includes` | no | string[] | Extra compile units linked before `entry` ([#452](https://github.com/PurHur/php-compiler/issues/452), [#752](https://github.com/PurHur/php-compiler/issues/752)) |
| `index` | no | string | Alternate index script (validated when present; prefer `entry` for builds) |
| `autoload` | no | object | PSR-4 autoload for `phpc serve` / lint ([#155](https://github.com/PurHur/php-compiler/issues/155)) |

### `entry`

Front controller or main script (often `public/index.php`). For multi-file apps, declare `autoload.psr-4` so `phpc build --project` discovers referenced classes via `ProjectGraph` ([#1762](https://github.com/PurHur/php-compiler/issues/1762)); use `includes[]` for files not reachable through PSR-4 or literal `require` discovery.

### `binary`

Relative path for the native executable produced by `phpc build`. `phpc validate-manifest` requires the file to exist (run `phpc build` first on fresh clones). `validateForBuild` only checks that `binary` is declared, not that it exists yet.

### `public`

When set, `phpc serve` uses this directory as the docroot instead of the cwd. `validate-manifest` also requires `public/index.php` to exist under that directory.

### `assets`

Directory of static files (CSS, images) copied into the deploy bundle. Separate from runtime PHP `include` paths inside templates ([#623](https://github.com/PurHur/php-compiler/issues/623), [#783](https://github.com/PurHur/php-compiler/issues/783), [#784](https://github.com/PurHur/php-compiler/issues/784)).

### `includes`

Ordered list of additional `.php` files compiled and linked **before** `entry`. PSR-4 paths from `autoload` are merged automatically by `ProjectGraph::resolve()`; use `includes[]` for bootstrap files, procedural helpers, or classes not referenced statically from the entry graph. Order matters for link-time symbol resolution when symbols overlap.

Runtime template `include` / `require` paths (for example `include __DIR__ . '/layout.php'`) are **not** listed here; the compiler discovers literals via `LiteralIncludeDiscovery` when bundling.

### `autoload`

```json
{
  "autoload": {
    "psr-4": {
      "App\\": "src/"
    }
  }
}
```

`phpc serve` registers a VM class autoloader from `autoload.psr-4` before compiling the entry script. `phpc lint --project` also lints PHP files under mapped directories. `phpc build --project` walks static class references (`new`, `extends`, `::class`, etc.) and adds matching PSR-4 paths to the link graph without hand-listed `includes[]` ([#1762](https://github.com/PurHur/php-compiler/issues/1762)); literal `require` paths from the entry still need `includes[]` when not reachable via autoload.

## Examples

### Minimal flat project (`examples/001-SimpleWeb/phpc.json`)

```json
{
    "entry": "example.php",
    "binary": ".phpc/bin/app"
}
```

### Multi-file web app (`examples/003-MiniWebApp/phpc.json`)

```json
{
    "entry": "public/index.php",
    "binary": ".phpc/bin/app",
    "public": "public",
    "assets": "assets",
    "includes": ["src/Router.php", "config.php"]
}
```

Compile unit order for `phpc build --project`: `src/Router.php`, `config.php`, then `public/index.php` (`ProjectManifest::resolveCompileUnitPaths`).

### JSON API flat project (`examples/004-ApiJson/phpc.json`)

```json
{
    "entry": "example.php",
    "binary": ".phpc/bin/app"
}
```

Same manifest as **001-SimpleWeb**; docroot is the project root (no `public/`). Scaffold with `phpc init --profile apijson` ([#2000](https://github.com/PurHur/php-compiler/issues/2000)); template parity: `script/check-init-apijson-parity.sh` ([#2029](https://github.com/PurHur/php-compiler/issues/2029)).

### Session flash flat project (`examples/005-SessionsWeb/phpc.json`)

```json
{
    "entry": "example.php",
    "binary": ".phpc/bin/app"
}
```

Same manifest as **001-SimpleWeb**; docroot is the project root (no `public/`). Scaffold with `phpc init --profile sessionsweb` ([#1886](https://github.com/PurHur/php-compiler/issues/1886)); template parity: `script/check-init-sessionsweb-parity.sh` ([#1902](https://github.com/PurHur/php-compiler/issues/1902)).

### Multipart upload flat project (`examples/006-FileUploadWeb/phpc.json`)

```json
{
    "entry": "example.php",
    "binary": ".phpc/bin/app"
}
```

Same manifest as **001-SimpleWeb**; docroot is the project root (no `public/`). Scaffold with `phpc init --profile fileupload` ([#2004](https://github.com/PurHur/php-compiler/issues/2004)); template parity: `script/check-init-fileupload-parity.sh` (default `INIT_FILEUPLOAD_PARITY_GATE=1` in `ci-fast`, **#2020**).

### Throw/catch flat project (`examples/007-ThrowsWeb/phpc.json`)

```json
{
    "entry": "example.php",
    "binary": ".phpc/bin/app"
}
```

Same manifest as **001-SimpleWeb**; docroot is the project root (no `public/`). Scaffold with `phpc init --profile throwsweb` ([#2092](https://github.com/PurHur/php-compiler/issues/2092)); template parity: `script/check-init-throwsweb-parity.sh` (default `INIT_THROWSWEB_PARITY_GATE=1` in `ci-fast`, **#2127**).

## `phpc init` profiles

| Profile | Template | Layout |
|---------|----------|--------|
| `default` | `templates/init/` | `public/index.php` hello world |
| `miniwebapp` | `templates/init-miniwebapp/` | Router + templates ([**003-MiniWebApp**](../examples/003-MiniWebApp/)) |
| `apijson` | `templates/init-apijson/` | Flat `example.php` JSON API ([**004-ApiJson**](../examples/004-ApiJson/)) |
| `sessionsweb` | `templates/init-sessionsweb/` | Flat `example.php` session flash ([**005-SessionsWeb**](../examples/005-SessionsWeb/)) |
| `fileupload` | `templates/init-fileupload/` | Flat `example.php` multipart upload ([**006-FileUploadWeb**](../examples/006-FileUploadWeb/)) |
| `throwsweb` | `templates/init-throwsweb/` | Flat `example.php` throw/catch validation ([**007-ThrowsWeb**](../examples/007-ThrowsWeb/)) |
| `selfhostprobe` | `templates/init-selfhostprobe/` | Flat `example.php` North Star 2 bootstrap presenter ([**008-SelfHostProbe**](../examples/008-SelfHostProbe/)) |
| `fastcgiweb` | `templates/init-fastcgiweb/` | Flat `example.php` FastCGI / deploy health + PATH_INFO ([**009-FastCGIWeb**](../examples/009-FastCGIWeb/)) |

### Self-host presenter flat project (`examples/008-SelfHostProbe/phpc.json`)

```json
{
    "entry": "example.php",
    "binary": ".phpc/bin/app"
}
```

CLI presenter only (no superglobals). Scaffold with `phpc init --profile selfhostprobe` ([#2220](https://github.com/PurHur/php-compiler/issues/2220)); template parity: `script/check-init-selfhostprobe-parity.sh` (default `INIT_SELFHOSTPROBE_PARITY_GATE=1` in `ci-fast`).

### FastCGI / deploy flat project (`examples/009-FastCGIWeb/phpc.json`)

```json
{
    "entry": "example.php",
    "binary": ".phpc/bin/app"
}
```

Health `ok` and PATH_INFO diagnostics via CGI superglobals. Scaffold with `phpc init --profile fastcgiweb` ([#2342](https://github.com/PurHur/php-compiler/issues/2342)); template parity: `script/check-init-fastcgiweb-parity.sh` (default `INIT_FASTCGIWEB_PARITY_GATE=1` in `ci-fast`).

## Commands

| Command | Role |
|---------|------|
| `phpc validate-manifest [dir]` | JSON schema, required keys, on-disk paths (default: cwd) |
| `phpc build --project [dir]` | AOT link using manifest `includes` + `entry` |
| `phpc serve [dir]` | VM CGI server; uses `public` when set |
| `phpc serve --aot [dir]` | Serve prebuilt `binary` with CGI env per request |
| `phpc deploy [dir]` | Copy `public`, `assets`, and binary into a deploy tree ([#609](https://github.com/PurHur/php-compiler/issues/609), [#635](https://github.com/PurHur/php-compiler/issues/635)) |

## Validation and CI

```console
phpc validate-manifest examples/003-MiniWebApp
```

Fast CI runs `ExamplesManifestTest` — every shipped `examples/*/phpc.json` must pass `phpc validate-manifest` ([#654](https://github.com/PurHur/php-compiler/issues/654)).

`phpc doctor` suggests `phpc validate-manifest` when the project manifest is missing or invalid.

## Run AOT binary with CGI env ([#774](https://github.com/PurHur/php-compiler/issues/774))

After `phpc build --project <dir>`, debug the linked binary without TCP or `phpc serve --aot`:

```console
phpc run --project examples/001-SimpleWeb \
  --cgi-env QUERY_STRING=name=Dev --cgi-env REQUEST_METHOD=GET

phpc run --project examples/001-SimpleWeb \
  --cgi-env-file test/fixtures/cgi-env/simpleweb-name-dev.env
```

| Flag | Purpose |
|------|---------|
| `--cgi-env KEY=VAL` | Set CGI variables before exec (repeatable) |
| `--cgi-env-file path` | Load `KEY=VAL` lines (`#` comments, optional `export` prefix) |
| `--deploy-root dist` | Set `PHPC_DEPLOY_ROOT` (deploy bundle layout, [#609](https://github.com/PurHur/php-compiler/issues/609)) |
| `--require-nonempty-stdout` | Exit `2` when stdout is empty (AOT execute probes, [#772](https://github.com/PurHur/php-compiler/issues/772)) |

Exit code is the binary's exit code unless `--require-nonempty-stdout` trips on empty stdout. VM scripts still use `phpc run script.php` with `-q` / `-p` (no `--project`).

JSON schema (editor hints): [phpc-json.schema.json](phpc-json.schema.json).

## Related docs

- [examples/README.md](../examples/README.md) — per-example run/build commands
- [docs/local-ci-matrix.md](local-ci-matrix.md) — MiniWebApp / examples smoke gates
- [docs/deploy-web-aot.md](deploy-web-aot.md) — AOT deploy layout
