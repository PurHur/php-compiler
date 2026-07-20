<?php

declare(strict_types=1);

/**
 * JIT/AOT helper for tempnam() via TempnamJitHelper PHP (#15685).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\SysGetTempDirRuntime;
use PHPCompiler\JIT\Builtin\StringTempnam;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

final class JitTempnam
{
    /** @return Value */
    public static function lowerDirectory(Context $context, JITVariable $arg): Value
    {
        if (self::isNullJitArg($arg)) {
            if ($context->callerStrictTypes || VmString::requiresTypedPathStringOnForwardProfile()) {
                JitInternalStrictArg::requireString($context, $arg, 'tempnam', 'directory', 1);

                return JitStringArg::lower($context, $arg, 'tempnam() directory');
            }
            SysGetTempDirRuntime::ensureLinked($context);

            return $context->builder->call(
                $context->lookupFunction('__compiler_sys_get_temp_dir')
            );
        }

        return JitStringBuiltinArg::lowerPath($context, $arg, 'tempnam', 0, 'directory');
    }

    private static function isNullJitArg(JITVariable $arg): bool
    {
        return JITVariable::TYPE_NULL === $arg->type;
    }

    /** @return Value */
    public static function invoke(Context $context, Value $dirStr, Value $prefixStr): Value
    {
        return StringTempnam::invoke($context, $dirStr, $prefixStr);
    }
}
