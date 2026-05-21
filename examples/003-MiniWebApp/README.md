# 003-MiniWebApp

Lint-first reference web app for [issue #67](https://github.com/PurHur/php-compiler/issues/67) and routing spec [#210](https://github.com/PurHur/php-compiler/issues/210). The tree documents **which language and OOP gaps block** a full front-controller app before VM/JIT/AOT serve is expected to work.

## Layout

```
examples/003-MiniWebApp/
  README.md
  phpc.json              # entry public/index.php, includes[] stub (#452)
  config.php
  public/index.php       # front controller (?route= until PATH_INFO #276)
  src/Router.php         # class dispatch (lint blockers #145)
  templates/             # layout + partials (__DIR__ includes lint-followed #462; runtime #54)
  assets/style.css       # static asset for future serve tests (#150)
```

## Lint (expected non-zero)

```console
./phpc lint --all examples/003-MiniWebApp
./phpc lint --all examples/003-MiniWebApp --json
```

During the skeleton phase this **must** exit `1` because of class/method blockers ([#58](https://github.com/PurHur/php-compiler/issues/58), [#145](https://github.com/PurHur/php-compiler/issues/145)). `__DIR__` + literal `include`/`require` paths are followed by lint ([#462](https://github.com/PurHur/php-compiler/issues/462)); stderr should not list `dynamic include/require` for this tree. When [#67](https://github.com/PurHur/php-compiler/issues/67) is done, `phpc lint --all` should exit `0` and `make web-smoke` may treat this tree as a green gate ([#455](https://github.com/PurHur/php-compiler/issues/455)):

```console
MINIWEBAPP_LINT_GATE=1 make web-smoke   # fail if lint --all regresses
```

### Blocker matrix (from `phpc lint --all`, 2026-05-21)

| Feature in source | Blocking issue | Lint node |
|-------------------|----------------|-----------|
| `class Router` + methods | [#145](https://github.com/PurHur/php-compiler/issues/145) | `Unsupported class body element: PHPCfg\Op\Stmt\ClassMethod` |
| `$router->dispatch(...)` | [#145](https://github.com/PurHur/php-compiler/issues/145) | `Expr_MethodCall` |
| `require __DIR__ . '/../config.php'` | [#54](https://github.com/PurHur/php-compiler/issues/54) runtime | ✅ lint follows ([#462](https://github.com/PurHur/php-compiler/issues/462)) |
| `include __DIR__ . '/../templates/...'` | [#54](https://github.com/PurHur/php-compiler/issues/54) runtime | ✅ lint follows ([#462](https://github.com/PurHur/php-compiler/issues/462)); VM scope ✅ ([#471](https://github.com/PurHur/php-compiler/issues/471)) |
| `foreach ($knownRoutes as $known)` | — | ✅ accepted (was [#53](https://github.com/PurHur/php-compiler/issues/53)) |
| `break` in route scan | [#115](https://github.com/PurHur/php-compiler/issues/115) | `Stmt_Break` (when lint follows index includes) |
| `$_GET['route'] ?? 'home'` | — | ✅ accepted (was [#99](https://github.com/PurHur/php-compiler/issues/99)) |
| `switch ($route)` in Router | — | ✅ accepted in VM (JIT case stubs [#96](https://github.com/PurHur/php-compiler/issues/96)) |
| `json_encode` / `http_response_code` API route | [#61](https://github.com/PurHur/php-compiler/issues/61), [#270](https://github.com/PurHur/php-compiler/issues/270) | blocked by class/method lint above |
| `phpc build --project` + `includes[]` | [#452](https://github.com/PurHur/php-compiler/issues/452) | manifest lists paths; AOT bundle pending |

Top diagnostics today:

```
examples/003-MiniWebApp/public/index.php: line 23: unsupported Expr_MethodCall
examples/003-MiniWebApp/src/Router.php: line 18: unsupported Unsupported class body element: PHPCfg\Op\Stmt\ClassMethod
```

## Routes (target)

| Method | Path | Behavior |
|--------|------|----------|
| GET | `?route=home` | Home template |
| GET | `?route=hello&name=` | Greet (001 parity) |
| POST | `?route=contact` | Form thank-you ([#248](https://github.com/PurHur/php-compiler/issues/248), [#259](https://github.com/PurHur/php-compiler/issues/259)) |
| GET | `?route=api/status` | JSON status ([#61](https://github.com/PurHur/php-compiler/issues/61)) |

## Run matrix (expect failure until green)

| Mode | Status | Command |
|------|--------|---------|
| Lint | ❌ skeleton | `./phpc lint --all .` |
| VM | ❌ blocked | `./phpc serve 127.0.0.1:8080 .` from this directory |
| JIT | ❌ blocked | [#207](https://github.com/PurHur/php-compiler/issues/207) |
| AOT | ❌ blocked | `../../phpc build --project .` (needs [#145](https://github.com/PurHur/php-compiler/issues/145), [#452](https://github.com/PurHur/php-compiler/issues/452)) |

### curl recipes (after VM unblocked)

```console
cd examples/003-MiniWebApp
../../phpc serve 127.0.0.1:8080 .
curl -s 'http://127.0.0.1:8080/?route=home'
curl -s 'http://127.0.0.1:8080/?route=hello&name=Dev'
curl -s -X POST -d 'name=PostDev' 'http://127.0.0.1:8080/?route=contact'
curl -s 'http://127.0.0.1:8080/?route=api/status'
```

## CI hooks (follow-ups)

- [#454](https://github.com/PurHur/php-compiler/issues/454) — `ExamplesCompileTest` `@group miniwebapp` (skipped until green)
- [#455](https://github.com/PurHur/php-compiler/issues/455) — `make web-smoke` runs `phpc lint --all` when `public/` exists (shipped)
- [#298](https://github.com/PurHur/php-compiler/issues/298) — extend `examples-web-smoke.sh` for 003 when serve is green

## Related

- [#246](https://github.com/PurHur/php-compiler/issues/246) — this skeleton
- [#203](https://github.com/PurHur/php-compiler/issues/203) — shipped `examples/*/example.php` gate
