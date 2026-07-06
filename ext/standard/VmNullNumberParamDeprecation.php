<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM;
use PHPCompiler\VM\Variable;

/** @internal php-src math.c — E_DEPRECATED before null→0 coercion for Z_PARAM_NUMBER (#16410). */
final class VmNullNumberParamDeprecation
{
    public static function message(string $function, int $argIndex, string $paramName): string
    {
        return sprintf(
            '%s(): Passing null to parameter #%d ($%s) of type int|float is deprecated',
            $function,
            $argIndex,
            $paramName
        );
    }

    public static function emit(?Frame $frame, string $function, int $argIndex, string $paramName): void
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
            self::message($function, $argIndex, $paramName),
            $vm->context,
            $frame
        );
    }
}
