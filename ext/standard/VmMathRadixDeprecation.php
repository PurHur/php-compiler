<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM;

/**
 * Engine E_DEPRECATED for invalid radix digits (php-src math.c _php_math_basetozval; #24950).
 *
 * Kept out of {@see MathBaseConvertJitHelper} NestedJIT deps — VM::running() must not enter the
 * runtime_safe helper unit. JIT/AOT emit via {@see MathBaseConvertRuntime} + {@see JitBuiltinWarning}.
 */
final class VmMathRadixDeprecation
{
    public static function emit(?Frame $frame = null): void
    {
        $vm = VM::running();
        if (null === $vm) {
            return;
        }
        if (null === $frame) {
            $frame = $vm->builtinHandlerFrame();
            if (null === $frame) {
                $frames = $vm->context->runStackFrames();
                $frame = [] !== $frames ? $frames[0] : null;
            }
        }
        $vm->context->errors->internalDeprecated(
            VmMath::INVALID_RADIX_CHARS_MESSAGE,
            $vm->context,
            $frame
        );
    }
}
