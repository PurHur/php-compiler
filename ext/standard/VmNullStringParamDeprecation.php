<?php

declare(strict_types=1);

/**
 * E_DEPRECATED for null passed to typed string / array|string builtin params (php-src ZPP).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM;

/** @internal */
final class VmNullStringParamDeprecation
{
    public static function message(
        string $function,
        int $argIndex,
        string $paramName,
        string $expectedType = 'string'
    ): string {
        return sprintf(
            '%s(): Passing null to parameter #%d ($%s) of type %s is deprecated',
            $function,
            $argIndex + 1,
            $paramName,
            $expectedType
        );
    }

    public static function emit(
        ?Frame $frame,
        string $function,
        int $argIndex,
        string $paramName,
        string $expectedType = 'string'
    ): void {
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
            self::message($function, $argIndex, $paramName, $expectedType),
            $vm->context,
            $frame
        );
    }
}
