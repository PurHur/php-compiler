# 004-ApiJson

Minimal JSON API endpoint for `phpc lint` / `ExamplesCompileTest` ([#270](https://github.com/PurHur/php-compiler/issues/270)).

## Run

```console
./phpc lint examples/004-ApiJson/example.php
./phpc run examples/004-ApiJson/example.php
./phpc serve 127.0.0.1:8080 examples/004-ApiJson
curl -s -D - 'http://127.0.0.1:8080/example.php'
```

Expected body:

```json
{"ok":true,"service":"php-compiler"}
```

## Blockers (closed)

| Feature | Issue | Status |
|---------|-------|--------|
| `json_encode()` | [#61](https://github.com/PurHur/php-compiler/issues/61) | VM + JIT |
| `http_response_code()` | [#252](https://github.com/PurHur/php-compiler/issues/252) | done |
| `header()` | [#55](https://github.com/PurHur/php-compiler/issues/55) / stdlib | done |

## Related

- [#67](https://github.com/PurHur/php-compiler/issues/67) reference app `/api/status`
- [#210](https://github.com/PurHur/php-compiler/issues/210) routing
- [#90](https://github.com/PurHur/php-compiler/issues/90) routing guards
