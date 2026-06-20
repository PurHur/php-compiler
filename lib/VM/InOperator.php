<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * `$needle in $haystack` runtime (PHP 8.3+ enum/array contains, #4682).
 *
 * php-src: strict `===` membership over array values (no recursion).
 * SSOT labels: {@see InOperatorJitHelper}
 */
final class InOperator
{
    public static function contains(Variable $needle, Variable $haystack): bool
    {
        if (Variable::TYPE_ARRAY !== $haystack->type) {
            throw new \TypeError(
                'Unsupported operand types: '
                .InOperatorJitHelper::vmOperandLabel($needle->type)
                .' in '
                .InOperatorJitHelper::vmOperandLabel($haystack->type)
            );
        }

        foreach ($haystack->toArray()->iterate(true) as $value) {
            if ($needle->identicalTo($value)) {
                return true;
            }
        }

        return false;
    }
}
