# Runtime semantics (VM / JIT / AOT)

Documented behavior for web and array access. See also [#176](https://github.com/PurHur/php-compiler/issues/176) (capability matrix).

## Undefined array keys ([#273](https://github.com/PurHur/php-compiler/issues/273))

When `error_reporting` includes `E_WARNING` (default in VM: full `E_ALL`):

| Context | Missing key read | Warning |
|---------|------------------|---------|
| VM (`bin/vm.php`, `phpc run`) | Value is `null` | `Warning: Undefined array key "key"` (string keys quoted; integer keys unquoted) |
| JIT / AOT (`__hashtable__` string keys) | Value is `null` / empty | Same message via `__compiler_undefined_array_key_warning_cstr` on stderr |

`isset($arr['missing'])` and `empty($arr['missing'])` do **not** emit warnings.

Writes to missing keys (`$arr['new'] = 1`) create the key without a warning (PHP behavior).

Recommended app pattern once [#99](https://github.com/PurHur/php-compiler/issues/99) lands: `$name = $_GET['name'] ?? 'Guest';`

## Verification

```bash
docker run --rm -v "$(pwd):/compiler" -w /compiler php-compiler:22.04-dev \
  ./phpc run examples/001-SimpleWeb/example.php
# Without ?name= — VM emits Warning on stderr; page still renders with null coerced in echo.

./script/ci-local.sh --filter UndefinedArrayKey
./script/ci-local.sh --filter undefined_array_key_get
```
