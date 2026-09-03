# Runtime semantics (VM / JIT / AOT)

Documented behavior for web and array access. See also [#176](https://github.com/PurHur/php-compiler/issues/176) (capability matrix).

## Memory model (#36397)

Numbered ownership invariants. php-src: [Zend/zend_gc.c](https://github.com/php/php-src/blob/master/Zend/zend_gc.c) `zend_gc_collect_cycles`, [Zend/zend_types.h](https://github.com/php/php-src/blob/master/Zend/zend_types.h) `ZEND_RC_MOD_CHECK`. Compile-time checks: `PHP_COMPILER_RUNTIME_ASSERT=1` (alias `PHPC_RUNTIME_ASSERT=1`) rebuilds helper `__ref__*` (use `PHP_COMPILER_HELPER_RUNTIME_O=0`). Link-time ASan/UBSan: `PHP_COMPILER_ASAN=1`.

| Id | Invariant | Who | When it fires |
|----|-----------|-----|----------------|
| **M1** | `refcount > 0` before `__ref__delref` decrements a counted header | `__ref__delref` | Double-delref or underflow; abort prints `PHPC_RUNTIME_ASSERT M1` |
| **M2** | Immortal / non-refcounted headers (`TYPE_INFO_REFCOUNTED` clear) must not take the counted delref path | `__ref__delref` early return | Literal strings, interned keys |
| **M3** | `__ref__addref` / `__ref__delref` own the count; callers never store `refcount` except init | lowering | Boxed temps, hashtable keys, objects |
| **M4** | `{main}` may defer object free (`phpc_destruct_delref_allowed`) but still unregisters GC / WeakRef at rc 0 | `__ref__delref` | Request-lifetime objects (#4013) |
| **M5** | Separate before writing a shared container (`rc > 1`) | `__ref__separate` | Arrays/strings COW |
| **M6** | Helper-unit objects must not mix NestedJIT vs Runtime ABI for the same symbol | helper cache | leftover `*.1` symbols (#31894) |
| **M7** | GC roots are objects whose rc hit 1 (`phpc_gc_register`); collector must not read after free | `GcCollectCyclesRuntime` | cyclic graphs (#36245) |

Enable M1 in IR:

```bash
./script/docker-exec.sh -- bash -lc 'source script/php-env.sh && PHP_COMPILER_RUNTIME_ASSERT=1 PHP_COMPILER_HELPER_RUNTIME_O=0 php bin/compile.php -o /tmp/x FILE.php'
```

Injected double-delref (unit test only): `PHP_COMPILER_RUNTIME_ASSERT_INJECT_DOUBLE_DELREF=1` calls `phpc_runtime_assert_inject_double_delref` (malloc counted header at rc 0, one delref) before `{main}`.

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
