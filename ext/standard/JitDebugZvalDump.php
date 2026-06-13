<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for debug_zval_dump() (#6084).
 *
 * Scalar output matches var_dump(); array/object refcount formatting stays VM-only
 * until a dedicated debug_zval_dump emitter is needed (issue defers to #4010 pattern).
 */
final class JitDebugZvalDump
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        return JitVarDump::invoke($context, ...$args);
    }
}
