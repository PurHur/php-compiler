<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;

/**
 * Compile-time StringTrimMode lowering for trim()/ltrim()/rtrim() (#7283).
 */
final class StringTrimModeJit
{
    public static function compileTimeModeBitmask(Context $context, JITVariable $arg): ?int
    {
        if (null === $arg->compileTimeConstantName || null === $context->runtime->vmContext) {
            return null;
        }
        $phpVar = $context->runtime->vmContext->constantFetch($arg->compileTimeConstantName);
        if (null === $phpVar) {
            return null;
        }

        return VmString::tryStringTrimModeBitmask($phpVar);
    }
}
