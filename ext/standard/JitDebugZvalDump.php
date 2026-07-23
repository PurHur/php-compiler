<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for debug_zval_dump() (#6084).
 *
 * Scalar output matches var_dump(); array/object refcount + interned markers stay
 * on the VM {@see VmDebugZval} path until a dedicated emitter lands (#22716 AOT).
 */
final class JitDebugZvalDump
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        return JitVarDump::invoke($context, ...$args);
    }
}
