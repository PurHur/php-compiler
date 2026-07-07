<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\VmArray;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;

/**
 * Compile-time ArrayPadType lowering for array_pad() (#17240).
 */
final class ArrayPadTypeJit
{
    public static function compileTimePadType(Context $context, JITVariable $arg): ?int
    {
        if (null === $arg->compileTimeConstantName || null === $context->runtime->vmContext) {
            return null;
        }
        $phpVar = $context->runtime->vmContext->constantFetch($arg->compileTimeConstantName);
        if (null === $phpVar) {
            return null;
        }

        return VmArray::tryArrayPadTypeInt($phpVar);
    }
}
