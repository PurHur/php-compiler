<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/**
 * getrusage() $mode parsing — typed int, Z_PARAM_LONG without bool coercion (php-src basic_functions.c; #11686).
 */
final class VmGetrusageArg
{
    public static function parseMode(Variable $var): int
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_BOOLEAN === $var->type) {
            throw new \TypeError(self::modeTypeError('bool'));
        }

        return VmMath::parseIntBuiltinArg($var, 'getrusage', 1, 'mode');
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
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            default => 'mixed',
        });
    }
}
