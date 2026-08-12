<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/**
 * getrusage() $mode parsing — typed int, Z_PARAM_LONG without bool coercion (php-src basic_functions.c; #11686).
 * Caller strict_types → TypeError on null (#30361).
 */
final class VmGetrusageArg
{
    public static function parseMode(Frame $frame, int $argIndex = 0): int
    {
        $var = $frame->calledArgs[$argIndex]->resolveIndirect();
        // php-src rejects bool for $mode (no bool→int); soft path would otherwise coerce via Z_PARAM_LONG (#11686).
        if (Variable::TYPE_BOOLEAN === $var->type) {
            throw new \TypeError(self::modeTypeError(EnumCaseSupport::typeNameForTypeErrorActual($var)));
        }

        // Z_PARAM_LONG — soft null DEP+0; strict_types null → TypeError (#30361).
        return VmMath::parseZParamLongBuiltinArgForFrame(
            $frame,
            $argIndex,
            'getrusage',
            1,
            'mode'
        );
    }

    private static function modeTypeError(string $given): string
    {
        return \sprintf(
            'getrusage(): Argument #1 ($%s) must be of type int, %s given',
            'mode',
            $given
        );
    }

    /** @internal JIT compile-time label */
    public static function modeTypeErrorForGiven(Variable $var): string
    {
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            return self::modeTypeError(EnumCaseSupport::typeNameForVariable($var));
        }

        return self::modeTypeError(match ($var->type) {
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => EnumCaseSupport::typeNameForTypeErrorActual($var),
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            default => 'mixed',
        });
    }
}
