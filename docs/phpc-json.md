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

Front controller or main script. For multi-file apps, list supporting classes in `includes[]` and keep `entry` as the bootstrap file (often `public/index.php`).

### `binary`

Relative path for the native executable produced by `phpc build`. `phpc validate-manifest` requires the file to exist (run `phpc build` first on fresh clones). `validateForBuild` only checks that `binary` is declared, not that it exists yet.

### `public`

When set, `phpc serve` uses this directory as the docroot instead of the cwd. `validate-manifest` also requires `public/index.php` to exist under that directory.

### `assets`

Directory of static files (CSS, images) copied into the deploy bundle. Separate from runtime PHP `include` paths inside templates ([#623](https://github.com/PurHur/php-compiler/issues/623), [#783](https://github.com/PurHur/php-compiler/issues/783), [#784](https://github.com/PurHur/php-compiler/issues/784)).

### `includes`

Ordered list of additional `.php` files compiled and linked **before** `entry`. Order matters for link-time symbol resolution (classes and functions used by the entry script must appear earlier in the list).

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

`phpc serve` registers a VM class autoloader from `autoload.psr-4` before compiling the entry script. `phpc lint --project` also lints PHP files under mapped directories. AOT project builds still require explicit `includes[]` until static discovery lands ([#155](https://github.com/PurHur/php-compiler/issues/155)).

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

### Session flash flat project (`examples/005-SessionsWeb/phpc.json`)

```json
{
    "entry": "example.php",
    "binary": ".phpc/bin/app"
}
```

No `public/` directory — docroot is the project root. See [examples/005-SessionsWeb/README.md](../examples/005-SessionsWeb/README.md) for cookie-jar curls.

## `phpc init` profiles

| Profile | Template | Layout |
|---------|----------|--------|
| `default` | `templates/init/` | `public/index.php` hello world |
| `miniwebapp` | `templates/init-miniwebapp/` | Router + templates (mirrors [examples/003-MiniWebApp](../examples/003-MiniWebApp/)) |
| `sessionsweb` | `templates/init-sessionsweb/` | Flat `example.php` session flash (mirrors [examples/005-SessionsWeb](../examples/005-SessionsWeb/)) |

```console
./phpc init --profile sessionsweb my-app
./phpc lint my-app/example.php
./phpc serve 127.0.0.1:8080 my-app
```

`templates/init-sessionsweb/` stays byte-identical to `examples/005-SessionsWeb/` on `example.php` and `phpc.json` — see `script/check-init-sessionsweb-parity.sh` ([#1902](https://github.com/PurHur/php-compiler/issues/1902)).

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
