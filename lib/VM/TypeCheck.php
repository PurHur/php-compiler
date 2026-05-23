<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Scalar type coercion for typed parameters (issue #156).
 */
final class TypeCheck
{
    public static function coerceParameter(Variable $dest, bool $strict): void
    {
        self::coerceTypedSlot($dest, $strict, 'Argument');
    }

    public static function coercePropertyWrite(Variable $dest, bool $strict): void
    {
        self::coerceTypedSlot($dest, $strict, 'Property');
    }

    private static function coerceTypedSlot(Variable $dest, bool $strict, string $kind): void
    {
        $target = $dest->resolveIndirect();
        $constraint = $target->typeConstraint;
        if (null === $constraint) {
            return;
        }
        $value = $target;
        if ($strict) {
            if (!self::isExactType($value, $constraint)) {
                throw new \TypeError(self::strictMessage($constraint, $value, $kind));
            }

            return;
        }
        if (self::isExactType($value, $constraint)) {
            return;
        }
        self::weakCoerceInPlace($target, $constraint, $value);
    }

    private static function isExactType(Variable $value, int $constraint): bool
    {
        return $value->type === $constraint;
    }

    private static function weakCoerceInPlace(Variable $dest, int $constraint, Variable $value): void
    {
        switch ($constraint) {
            case Variable::TYPE_INTEGER:
                $dest->int(self::coerceToInt($value));
                return;
            case Variable::TYPE_FLOAT:
                $dest->float(self::coerceToFloat($value));
                return;
            case Variable::TYPE_BOOLEAN:
                $dest->bool(self::coerceToBool($value));
                return;
            case Variable::TYPE_STRING:
                $dest->string($value->toString());
                return;
            case Variable::TYPE_ARRAY:
                if (Variable::TYPE_ARRAY === $value->type) {
                    return;
                }
                break;
        }
        throw new \TypeError(self::strictMessage($constraint, $value, $kind));
    }

    private static function coerceToInt(Variable $value): int
    {
        switch ($value->type) {
            case Variable::TYPE_INTEGER:
                return $value->toInt();
            case Variable::TYPE_BOOLEAN:
                return $value->toBool() ? 1 : 0;
            case Variable::TYPE_FLOAT:
                $f = $value->toFloat();
                if ($f !== (float) (int) $f) {
                    throw new \TypeError('Argument must be of type int, float given');
                }

                return (int) $f;
            case Variable::TYPE_STRING:
                $s = $value->toString();
                if (!is_numeric($s) || ((string) (int) $s) !== $s && ((string) (float) $s) !== $s) {
                    throw new \TypeError('Argument must be of type int, string given');
                }
                if (((string) (int) $s) === $s) {
                    return (int) $s;
                }
                $f = (float) $s;
                if ($f !== (float) (int) $f) {
                    throw new \TypeError('Argument must be of type int, string given');
                }

                return (int) $f;
        }
        throw new \TypeError('Argument must be of type int');
    }

    private static function coerceToFloat(Variable $value): float
    {
        switch ($value->type) {
            case Variable::TYPE_FLOAT:
                return $value->toFloat();
            case Variable::TYPE_INTEGER:
                return (float) $value->toInt();
            case Variable::TYPE_BOOLEAN:
                return $value->toBool() ? 1.0 : 0.0;
            case Variable::TYPE_STRING:
                $s = $value->toString();
                if (!is_numeric($s)) {
                    throw new \TypeError('Argument must be of type float, string given');
                }

                return (float) $s;
        }
        throw new \TypeError('Argument must be of type float');
    }

    private static function coerceToBool(Variable $value): bool
    {
        switch ($value->type) {
            case Variable::TYPE_BOOLEAN:
                return $value->toBool();
            case Variable::TYPE_INTEGER:
                return 0 !== $value->toInt();
            case Variable::TYPE_FLOAT:
                return 0.0 !== $value->toFloat();
            case Variable::TYPE_STRING:
                $lower = strtolower($value->toString());
                if (in_array($lower, ['1', 'true', 'on', 'yes'], true)) {
                    return true;
                }
                if (in_array($lower, ['0', 'false', 'off', 'no', ''], true)) {
                    return false;
                }
                throw new \TypeError('Argument must be of type bool, string given');
        }
        throw new \TypeError('Argument must be of type bool');
    }

    private static function strictMessage(int $constraint, Variable $value, string $kind = 'Argument'): string
    {
        $expected = self::typeName($constraint);
        $given = self::typeName($value->type);

        return "{$kind} must be of type {$expected}, {$given} given";
    }

    private static function typeName(int $type): string
    {
        switch ($type) {
            case Variable::TYPE_INTEGER:
                return 'int';
            case Variable::TYPE_FLOAT:
                return 'float';
            case Variable::TYPE_BOOLEAN:
                return 'bool';
            case Variable::TYPE_STRING:
                return 'string';
            case Variable::TYPE_NULL:
                return 'null';
            case Variable::TYPE_ARRAY:
                return 'array';
            case Variable::TYPE_OBJECT:
                return 'object';
            default:
                return 'mixed';
        }
    }
}
