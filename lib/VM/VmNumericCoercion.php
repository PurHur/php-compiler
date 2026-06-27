<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Zend zval_get_long semantics for internal builtins (ext/standard/array.c, #12867).
 */
final class VmNumericCoercion
{
    /**
     * php-src Zend/zend_operators.c zval_get_long — coerce Countable::count() result in count().
     */
    public static function zvalGetLong(Variable $value): int
    {
        $value = $value->resolveIndirect();

        return match ($value->type) {
            Variable::TYPE_NULL => 0,
            Variable::TYPE_INTEGER => $value->toInt(),
            Variable::TYPE_BOOLEAN => $value->toBool() ? 1 : 0,
            Variable::TYPE_FLOAT => self::floatToZendLong($value->toFloat()),
            Variable::TYPE_STRING => self::stringToZendLong($value->toString()),
            default => 0,
        };
    }

    private static function floatToZendLong(float $value): int
    {
        if ($value >= 0.0) {
            return (int) floor($value);
        }

        return (int) ceil($value);
    }

    private static function stringToZendLong(string $s): int
    {
        if (!is_numeric($s)) {
            return 0;
        }

        return (int) (float) $s;
    }
}
