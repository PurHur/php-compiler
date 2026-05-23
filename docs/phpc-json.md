# phpc.json project manifest

Schema: [phpc-json.schema.json](phpc-json.schema.json). Deploy and AOT workflow: [deploy-web-aot.md](deploy-web-aot.md).

## Keys

| Key | Role |
|-----|------|
| `entry` | Main PHP script for `phpc build --project` |
| `binary` | Output path for the native executable (e.g. `.phpc/bin/app`) |
| `includes` | Extra compile units before `entry` |
| `public` | Document root for `phpc serve` |
| `assets` | Static files directory |

## Run AOT binary with CGI env (#774)

After `phpc build --project <dir>`, debug the linked binary without TCP or `phpc serve --aot`:

```bash
phpc run --project examples/001-SimpleWeb \
  --cgi-env QUERY_STRING=name=Dev --cgi-env REQUEST_METHOD=GET

phpc run --project examples/001-SimpleWeb \
  --cgi-env-file test/fixtures/cgi-env/simpleweb-name-dev.env
```

| Flag | Purpose |
|------|---------|
| `--cgi-env KEY=VAL` | Set CGI variables before exec (repeatable) |
| `--cgi-env-file path` | Load `KEY=VAL` lines (`#` comments, optional `export` prefix) |
| `--deploy-root dist` | Set `PHPC_DEPLOY_ROOT` (deploy bundle layout, #609) |
| `--require-nonempty-stdout` | Exit `2` when stdout is empty (AOT execute probes, #772) |

Exit code is the binary's exit code unless `--require-nonempty-stdout` trips on empty stdout.

VM scripts still use `phpc run script.php` with `-q` / `-p` (no `--project`).
