<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * `$needle in $haystack` runtime (PHP 8.3+ enum/array contains, #4682).
 *
 * php-src: strict `===` membership over array values (no recursion).
 */
final class InOperator
{
    public static function contains(Variable $needle, Variable $haystack): bool
    {
        if (Variable::TYPE_ARRAY !== $haystack->type) {
            throw new \TypeError(
                'Unsupported operand types: '
                .self::operandLabel($needle)
                .' in '
                .self::operandLabel($haystack)
            );
        }

        foreach ($haystack->toArray()->iterate(true) as $value) {
            if ($needle->identicalTo($value)) {
                return true;
            }
        }

        return false;
    }

    private static function operandLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            Variable::TYPE_ENUM_CASE => 'enum',
            default => 'mixed',
        };
    }
}
