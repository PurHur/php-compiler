<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\GenericArrayTypeSpec;

/**
 * Scalar type coercion for typed parameters (issue #156) and typed properties (#169).
 */
final class TypeCheck
{
    public static function coerceParameter(Variable $dest, bool $strict, ?GenericArrayTypeSpec $arraySpec = null): void
    {
        self::coerceTypedSlot($dest, $strict, 'Argument');
        if (null !== $arraySpec) {
            self::assertGenericArrayShape($dest, $arraySpec, 'Argument');
        }
    }

    /**
     * @param list<string> $interfaceLcs
     */
    public static function assertParamIntersection(
        Variable $dest,
        array $interfaceLcs,
        Context $context
    ): void {
        InterfaceCheck::assertObjectImplementsAll($dest, $interfaceLcs, $context, 'Argument');
    }

    public static function coercePropertyWrite(Variable $dest, bool $strict): void
    {
        $target = $dest->resolveIndirect();
        if (null !== $target->dnfArms) {
            return;
        }
        if (null !== $target->unionTypeConstraints) {
            self::coerceUnionPropertyWrite($target, $strict);

            return;
        }
        self::coerceTypedSlot($dest, $strict, 'Property', null, true);
        $resolved = $dest->resolveIndirect();
        if (null !== $resolved->genericArrayTypeSpec) {
            self::assertGenericArrayShape($resolved, $resolved->genericArrayTypeSpec, 'Property');
        }
    }

    public static function coerceReturn(Variable $value, bool $strict, int $constraint): void
    {
        self::coerceTypedSlot($value, $strict, 'Return value', $constraint);
    }

    public static function assertVoidReturn(?Variable $value): void
    {
        if (null !== $value) {
            throw new \TypeError('A void function must not return a value');
        }
    }

    public static function assertNeverReturn(): void
    {
        throw new \TypeError('A never-returning function must not return');
    }

    public static function assertStaticReturn(
        Variable $value,
        string $expectedClassLc,
        Context $context
    ): void {
        $target = $value->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $target->type) {
            throw new \TypeError(
                'Return value must be of type static, '.self::typeName($target->type).' given'
            );
        }
        $entry = $target->toObject()->class;
        if (!InterfaceCheck::entryIsInstanceOf($entry, $expectedClassLc, $context)) {
            $expectedName = $context->classes[$expectedClassLc]->name ?? $expectedClassLc;

            throw new \TypeError(
                "Return value must be of type {$expectedName}, {$entry->name} returned"
            );
        }
    }

    private static function coerceUnionPropertyWrite(Variable $target, bool $strict): void
    {
        $constraints = $target->unionTypeConstraints ?? [];
        if ([] === $constraints) {
            return;
        }
        foreach ($constraints as $constraint) {
            $trial = clone $target;
            try {
                self::coerceTypedSlot($trial, $strict, 'Property', $constraint, true);
                $target->copyFrom($trial);

                return;
            } catch (\TypeError $e) {
                continue;
            }
        }

        throw self::propertyTypeError(
            $target,
            $target->declaredTypeLabel ?? 'mixed',
            $target
        );
    }

    private static function coerceTypedSlot(
        Variable $dest,
        bool $strict,
        string $kind,
        ?int $constraint = null,
        bool $propertyWrite = false
    ): void {
        $target = $dest->resolveIndirect();
        $constraint ??= $target->typeConstraint;
        if (null === $constraint) {
            return;
        }
        $value = $target;
        if ($strict) {
            if (!self::isExactType($value, $constraint)) {
                throw self::typedSlotError($target, $constraint, $value, $kind, $propertyWrite);
            }

            return;
        }
        if (self::isExactType($value, $constraint)) {
            return;
        }
        try {
            self::weakCoerceInPlace($target, $constraint, $value, $kind, $propertyWrite);
        } catch (\TypeError $e) {
            throw self::typedSlotError($target, $constraint, $value, $kind, $propertyWrite);
        }
    }

    private static function isExactType(Variable $value, int $constraint): bool
    {
        return $value->type === $constraint;
    }

    private static function weakCoerceInPlace(
        Variable $dest,
        int $constraint,
        Variable $value,
        string $kind,
        bool $propertyWrite = false
    ): void {
        switch ($constraint) {
            case Variable::TYPE_INTEGER:
                $dest->int(self::coerceToInt($value, $kind));
                return;
            case Variable::TYPE_FLOAT:
                $dest->float(self::coerceToFloat($value, $kind));
                return;
            case Variable::TYPE_BOOLEAN:
                $dest->bool(self::coerceToBool($value, $kind));
                return;
            case Variable::TYPE_STRING:
                if (Variable::TYPE_ARRAY === $value->type || Variable::TYPE_OBJECT === $value->type) {
                    throw new \TypeError(self::strictMessage($constraint, $value, $kind));
                }
                $dest->string($value->toString());
                return;
            case Variable::TYPE_ARRAY:
                if (Variable::TYPE_ARRAY === $value->type) {
                    return;
                }
                break;
            case Variable::TYPE_NULL:
                if (Variable::TYPE_NULL === $value->type) {
                    return;
                }
                break;
        }
        throw new \TypeError(self::strictMessage($constraint, $value, $kind));
    }

    private static function coerceToInt(Variable $value, string $kind = 'Argument'): int
    {
        switch ($value->type) {
            case Variable::TYPE_INTEGER:
                return $value->toInt();
            case Variable::TYPE_BOOLEAN:
                return $value->toBool() ? 1 : 0;
            case Variable::TYPE_FLOAT:
                $f = $value->toFloat();
                if ($f !== (float) (int) $f) {
                    throw new \TypeError("{$kind} must be of type int, float given");
                }

                return (int) $f;
            case Variable::TYPE_STRING:
                $s = $value->toString();
                if (!is_numeric($s) || ((string) (int) $s) !== $s && ((string) (float) $s) !== $s) {
                    throw new \TypeError("{$kind} must be of type int, string given");
                }
                if (((string) (int) $s) === $s) {
                    return (int) $s;
                }
                $f = (float) $s;
                if ($f !== (float) (int) $f) {
                    throw new \TypeError("{$kind} must be of type int, string given");
                }

                return (int) $f;
        }
        throw new \TypeError("{$kind} must be of type int");
    }

    private static function coerceToFloat(Variable $value, string $kind = 'Argument'): float
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
                    throw new \TypeError("{$kind} must be of type float, string given");
                }

                return (float) $s;
        }
        throw new \TypeError("{$kind} must be of type float");
    }

    private static function coerceToBool(Variable $value, string $kind = 'Argument'): bool
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
                throw new \TypeError("{$kind} must be of type bool, string given");
        }
        throw new \TypeError("{$kind} must be of type bool");
    }

    private static function typedSlotError(
        Variable $target,
        int $constraint,
        Variable $value,
        string $kind,
        bool $propertyWrite
    ): \TypeError {
        if ($propertyWrite && 'Property' === $kind) {
            return self::propertyTypeError($target, self::typeName($constraint), $value);
        }

        return new \TypeError(self::strictMessage($constraint, $value, $kind));
    }

    private static function propertyTypeError(
        Variable $target,
        string $expectedType,
        Variable $value
    ): \TypeError {
        $owner = $target->objectPropertyOwner;
        $propName = $target->objectPropertyName ?? 'property';
        if (null !== $owner) {
            return new \TypeError(sprintf(
                'Cannot assign %s to property %s::$%s of type %s',
                self::typeName($value->type),
                $owner->class->name,
                $propName,
                $expectedType
            ));
        }

        return new \TypeError(self::strictMessage(
            $target->typeConstraint ?? Variable::TYPE_INTEGER,
            $value,
            'Property'
        ));
    }

    private static function strictMessage(int $constraint, Variable $value, string $kind = 'Argument'): string
    {
        $expected = self::typeName($constraint);
        $given = self::typeName($value->type);

        return "{$kind} must be of type {$expected}, {$given} given";
    }

    private static function assertGenericArrayShape(Variable $dest, GenericArrayTypeSpec $spec, string $kind): void
    {
        $value = $dest->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $value->type) {
            return;
        }
        if (GenericArrayTypeSpec::KIND_LIST === $spec->kind && !self::arrayValueIsList($value)) {
            throw new \TypeError(
                "{$kind} must be of type list, array given"
            );
        }
    }

    private static function arrayValueIsList(Variable $value): bool
    {
        $ht = $value->toArray();
        $index = 0;
        foreach ($ht->iterateKeyed(true) as [$keyVar]) {
            if (Variable::TYPE_INTEGER !== $keyVar->type) {
                return false;
            }
            if ($keyVar->toInt() !== $index) {
                return false;
            }
            ++$index;
        }

        return true;
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
