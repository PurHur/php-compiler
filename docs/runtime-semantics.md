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

Recommended app pattern: `$name = $_GET['name'] ?? 'Guest';` (no undefined-key warning; see `test/real/cases/coalesce_get_default.phpt`).

## Verification

```bash
./script/docker-exec.sh -- ./phpc run examples/001-SimpleWeb/example.php
# Without ?name= — VM emits Warning on stderr; page still renders with null coerced in echo.

./script/ci-local.sh --filter UndefinedArrayKey
./script/ci-local.sh --filter undefined_array_key_get
# Docker:
./script/docker-exec.sh -- bash -lc 'source script/php-env.sh && ./script/ci-local.sh --filter UndefinedArrayKey'
```

## Stdlib argument type errors ([#6267](https://github.com/PurHur/php-compiler/issues/6267))

User-visible Z_PARAM-style mistakes in `ext/standard` builtins must raise catchable **`TypeError`** (or **`ValueError`** where php-src does), not **`LogicException`**. The latter is reserved for compiler-internal faults and often aborts the VM instead of unwinding through user `try/catch`.

| Layer | Path |
|-------|------|
| VM | `VmString::coerceStringBuiltinArg()`, `VmStreamArg::requireStreamHandle()`, path helpers on builtins |
| JIT/AOT | `JitStringBuiltinArg::lower()` mirroring the same guards |

Enum-case operands must name the enum class in the message (php-src-strict; see [#5780](https://github.com/PurHur/php-compiler/issues/5780)).

Verification:

```bash
./script/docker-exec.sh -- bash -lc 'source script/php-env.sh && ./script/ci-local.sh --filter logic_exception_enum'
```

## Runtime strictness modes ([#7361](https://github.com/PurHur/php-compiler/issues/7361))

Two complementary strictness layers (not `declare(strict_types=1)` on user functions):

| Mode | Env | When | Behavior |
|------|-----|------|----------|
| **php-src-strict** (default) | unset or `PHP_COMPILER_RUNTIME_STRICT=php-src` | VM/JIT/AOT compliance + app code | Full Zend guards (`VmString::coerceStringBuiltinArg`, enum-case checks, JIT `JitStringBuiltinArg`) |
| **php-compiler-strict** (opt-in) | `PHP_COMPILER_RUNTIME_STRICT=php-compiler` | Self-host/AOT with static proof in PR | May skip specific guard branches; **never** default in CI |

Policy helper: `lib/RuntimeStrictness.php`. CI wrappers (`ci-local.sh`, `ci-fast.sh`) **fail** if `PHP_COMPILER_RUNTIME_STRICT=php-compiler`.

Self-host-only guard skipping lands in follow-up issues with proof obligations (see [#5780](https://github.com/PurHur/php-compiler/issues/5780) enum-case parity).

Verification:

```bash
./script/docker-exec.sh -- bash -lc 'vendor/bin/phpunit --filter RuntimeStrictness'
```

## Source preprocess pipeline ([#6654](https://github.com/PurHur/php-compiler/issues/6654))

All parse entrypoints (VM `Runtime::parse()`, AOT include discovery, lint) must run the same preprocess chain before php-parser / PHPCfg. **`Runtime::prepareSourceForParser()`** is the SSOT helper; it runs sealed/static preprocessors, **`PropertyHooks`** (lowering block/arrow hook syntax), **`CurlyBraceOffsetRejector`**, enum/switch/generic rewriters, bare-throw rewrite, then parser desugar passes (`GlobalTypedConstRewriter`, `PipeOperatorDesugar`, etc.).

Property-hook block syntax (`public $x { get { … } }`) must be lowered **before** the curly-brace rejector (#6650); otherwise AOT include discovery that calls `Parser::parse()` directly would fatal on `{` in hook bodies.

Verification:

```bash
./script/docker-exec.sh -- bash -lc 'vendor/bin/phpunit --filter RuntimePreprocessTest'
```
