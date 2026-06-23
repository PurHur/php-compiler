<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Block;
use PHPCompiler\GenericArrayTypeSpec;

/**
 * Scalar type coercion for typed parameters (issue #156) and typed properties (#169).
 */
final class TypeCheck
{
    public static function assertNeverParameter(Variable $argument): void
    {
        throw new \TypeError(
            'Argument must be of type never, '.self::valueTypeLabel($argument).' given'
        );
    }

    public static function variadicSlotNeedsElementChecks(Block $block, int $slot): bool
    {
        return isset($block->paramNeverSlots[$slot])
            || isset($block->paramIterableSlots[$slot])
            || isset($block->paramVariadicElementTypeConstraints[$slot])
            || isset($block->paramVariadicElementGenericArrayTypeSpecs[$slot])
            || isset($block->paramVariadicElementIntersectionConstraints[$slot])
            || isset($block->paramVariadicElementDnfConstraints[$slot]);
    }

    /**
     * Zend zend_verify_variadic_arg_type(): declared type applies to each trailing arg (#4185).
     *
     * @param list<Variable> $elements
     * @param list<string>|null $intersection
     * @param list<list<array{kind: string, interfaces?: list<string>, display?: string, name?: string}>>|null $dnfArms
     */
    public static function verifyVariadicElements(
        array $elements,
        bool $strict,
        ?int $typeConstraint,
        ?GenericArrayTypeSpec $arraySpec,
        ?array $intersection,
        ?array $dnfArms,
        Context $context,
        bool $iterableElement = false,
        bool $neverElement = false,
        ?string $intersectionDisplay = null
    ): void {
        foreach ($elements as $element) {
            $probe = new Variable();
            $probe->copyFrom($element);
            $resolved = $probe->resolveIndirect();
            if ($neverElement) {
                self::assertNeverParameter($probe);

                continue;
            }
            if ($iterableElement) {
                IterableCheck::assertParameter($probe, $context);

                continue;
            }
            if (null !== $dnfArms) {
                DnfCheck::assertMatches($probe, $dnfArms, $context);

                continue;
            }
            if (null !== $intersection) {
                self::assertParamIntersection($probe, $intersection, $context, $intersectionDisplay);

                continue;
            }
            if (null !== $typeConstraint) {
                $resolved->typeConstraint = $typeConstraint;
            }
            self::coerceParameter($probe, $strict, $arraySpec);
        }
    }

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
        Context $context,
        ?string $expectedDisplay = null
    ): void {
        InterfaceCheck::assertObjectImplementsAll($dest, $interfaceLcs, $context, 'Argument', $expectedDisplay);
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
        $kind = null !== $target->functionStaticVarName ? 'Static variable' : 'Property';
        self::coerceTypedSlot($dest, $strict, $kind, null, true);
        $resolved = $dest->resolveIndirect();
        if (null !== $resolved->genericArrayTypeSpec) {
            self::assertGenericArrayShape($resolved, $resolved->genericArrayTypeSpec, $kind);
        }
    }

    public static function coerceFunctionStaticWrite(Variable $dest, bool $strict): void
    {
        self::coercePropertyWrite($dest, $strict);
    }

    /**
     * Property get hook return must match declared property type (zend_property_hooks.c, #7301).
     */
    public static function assertPropertyHookGetReturn(
        Variable $value,
        Variable $prototype,
        bool $strict,
        Context $context
    ): void {
        $meta = $prototype->resolveIndirect();
        if (null !== $meta->dnfArms) {
            DnfCheck::assertMatches($value, $meta->dnfArms, $context, 'Return value');

            return;
        }
        if (Variable::TYPE_NULL === $value->resolveIndirect()->type
            && TypedPropertyCheck::propertyAllowsNull($prototype)) {
            return;
        }
        $probe = new Variable();
        $probe->copyFrom($value);
        self::bindPropertyTypeMetadata($probe, $meta);
        self::coercePropertyWrite($probe, $strict);
        $value->copyFrom($probe);
    }

    private static function bindPropertyTypeMetadata(Variable $dest, Variable $typeMeta): void
    {
        $resolved = $dest->resolveIndirect();
        $resolved->typeConstraint = $typeMeta->typeConstraint;
        $resolved->classConstraint = $typeMeta->classConstraint;
        $resolved->literalBoolType = $typeMeta->literalBoolType;
        $resolved->unionTypeConstraints = $typeMeta->unionTypeConstraints;
        $resolved->declaredTypeLabel = $typeMeta->declaredTypeLabel;
        $resolved->genericArrayTypeSpec = $typeMeta->genericArrayTypeSpec;
        $resolved->dnfArms = $typeMeta->dnfArms;
    }

    public static function coerceReturn(
        Variable $value,
        bool $strict,
        int $constraint,
        ?string $literalBoolType = null
    ): void {
        if (null !== $literalBoolType) {
            $probe = new Variable();
            $probe->copyFrom($value);
            $probe->resolveIndirect()->literalBoolType = $literalBoolType;
            self::coerceTypedSlot($probe, true, 'Return value', $constraint);

            return;
        }
        self::coerceTypedSlot($value, $strict, 'Return value', $constraint);
    }

    /**
     * PHP 8.3 typed class constants: strict match; int literal allowed for float (zend_compile.c, #4541).
     */
    public static function assertClassConstantValue(
        Variable $value,
        int $constraint,
        ?string $constName = null
    ): void {
        $target = $value->resolveIndirect();
        if (self::isExactType($target, $constraint)) {
            return;
        }
        if (Variable::TYPE_FLOAT === $constraint && Variable::TYPE_INTEGER === $target->type) {
            $target->float((float) $target->toInt());

            return;
        }
        $expected = self::typeName($constraint);
        $given = self::typeName($target->type);
        if (null !== $constName && '' !== $constName) {
            throw new \TypeError("Cannot assign {$given} to class constant {$constName} of type {$expected}");
        }

        throw new \TypeError("Cannot assign {$given} to class constant of type {$expected}");
    }

    /**
     * PHP 8.3+ typed compile-unit constants (#7081).
     */
    public static function assertGlobalConstantTypedValue(
        Variable $value,
        Variable $typeMeta,
        ?string $constName = null
    ): void {
        try {
            self::assertClassConstantTypedValue($value, $typeMeta, $constName);
        } catch (\TypeError $e) {
            throw new \TypeError(str_replace('class constant', 'constant', $e->getMessage()), $e->getCode(), $e);
        }
    }

    /**
     * PHP 8.3+ union typed class constants (zend_compile_const_decl, #6886).
     */
    public static function assertClassConstantTypedValue(
        Variable $value,
        Variable $typeMeta,
        ?string $constName = null
    ): void {
        if (null !== $typeMeta->unionTypeConstraints) {
            self::assertClassConstantUnionValue(
                $value,
                $typeMeta->unionTypeConstraints,
                $constName,
                $typeMeta->declaredTypeLabel
            );

            return;
        }
        if (null !== $typeMeta->typeConstraint) {
            if (
                Variable::TYPE_OBJECT === $typeMeta->typeConstraint
                && null !== $typeMeta->classConstraint
                && self::matchesClassTypeHint($value, $typeMeta->classConstraint)
            ) {
                return;
            }
            self::assertClassConstantValue($value, $typeMeta->typeConstraint, $constName);
        }
    }

    /**
     * @param list<int> $constraints
     */
    public static function assertClassConstantUnionValue(
        Variable $value,
        array $constraints,
        ?string $constName = null,
        ?string $typeLabel = null
    ): void {
        if ([] === $constraints) {
            return;
        }
        $target = $value->resolveIndirect();
        foreach ($constraints as $constraint) {
            $trial = new Variable();
            $trial->copyFrom($target);
            try {
                self::assertClassConstantValue($trial, $constraint, null);
                $value->copyFrom($trial);

                return;
            } catch (\TypeError $e) {
                continue;
            }
        }
        $expected = $typeLabel ?? 'mixed';
        $given = self::typeName($target->type);
        if (null !== $constName && '' !== $constName) {
            throw new \TypeError("Cannot assign {$given} to class constant {$constName} of type {$expected}");
        }

        throw new \TypeError("Cannot assign {$given} to class constant of type {$expected}");
    }

    public static function assertVoidReturn(?Variable $value): void
    {
        if (null !== $value) {
            throw new \TypeError('A void function must not return a value');
        }
    }

    public static function assertNeverReturn(?string $functionName = null): void
    {
        if (null !== $functionName && '' !== $functionName) {
            throw new \TypeError("{$functionName}(): never-returning function must not implicitly return");
        }

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

    public static function assertObjectReturn(
        Variable $value,
        string $classConstraint,
        string $declaredLabel,
        ?string $callableName = null
    ): void {
        if (self::matchesClassTypeHint($value, $classConstraint)) {
            return;
        }
        $expected = ltrim($declaredLabel, '\\');
        $given = self::valueTypeLabel($value);
        $message = "Return value must be of type {$expected}, {$given} returned";
        if (null !== $callableName && '' !== $callableName) {
            $message = "{$callableName}(): {$message}";
        }

        throw new \TypeError($message);
    }

    private static function coerceUnionPropertyWrite(Variable $target, bool $strict): void
    {
        $constraints = $target->unionTypeConstraints ?? [];
        if ([] === $constraints) {
            return;
        }
        if (Variable::TYPE_NULL === $target->type) {
            if (\in_array(Variable::TYPE_NULL, $constraints, true)) {
                return;
            }

            throw self::propertyTypeError(
                $target,
                $target->declaredTypeLabel ?? 'mixed',
                $target
            );
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
        if (null !== $target->literalBoolType) {
            self::assertLiteralBool($dest, $target->literalBoolType, $kind, $propertyWrite, $constraint);

            return;
        }
        $value = $target;
        if (Variable::TYPE_OBJECT === $constraint && null !== $target->classConstraint) {
            if (self::matchesClassTypeHint($value, $target->classConstraint)) {
                return;
            }
            $expected = $target->declaredTypeLabel ?? self::normalizeClassLabel($target->classConstraint);
            if ($propertyWrite && ('Property' === $kind || 'Static variable' === $kind)) {
                throw self::propertyTypeError($target, $expected, $value);
            }

            throw self::typedSlotError($target, $constraint, $value, $kind, $propertyWrite, null, $expected);
        }
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

    public static function parameterMatchesType(
        Variable $value,
        int $constraint,
        ?string $literalBoolType = null
    ): bool {
        if (null !== $literalBoolType) {
            return self::matchesLiteralBool($value, $literalBoolType);
        }

        return self::isExactType($value->resolveIndirect(), $constraint);
    }

    /**
     * Zend 8.2: `int $x = null` accepts null at call sites (implicit nullable, #4449).
     */
    public static function skipParameterTypeCheckForImplicitNullable(
        Block $block,
        int $slot,
        Variable $argument
    ): bool {
        return isset($block->paramImplicitNullable[$slot])
            && Variable::TYPE_NULL === $argument->resolveIndirect()->type;
    }

    public static function typeNameForConstraint(int $type, ?string $literalBoolType = null): string
    {
        if (null !== $literalBoolType) {
            return $literalBoolType;
        }

        return self::typeName($type);
    }

    /**
     * Zend ZEND_FETCH_DIM_R on null/bool/int/float (zend_execute.c, #4867).
     */
    public static function isScalarNonContainerDimRead(Variable $value): bool
    {
        $resolved = $value->resolveIndirect();

        return \in_array($resolved->type, [
            Variable::TYPE_NULL,
            Variable::TYPE_BOOLEAN,
            Variable::TYPE_INTEGER,
            Variable::TYPE_FLOAT,
        ], true);
    }

    /**
     * Property fetch on values that are not objects (zend_execute.c, #5276).
     */
    public static function isNonObjectPropertyFetchReceiver(Variable $value): bool
    {
        $resolved = $value->resolveIndirect();

        return !\in_array($resolved->type, [
            Variable::TYPE_OBJECT,
            Variable::TYPE_ENUM_CASE,
        ], true);
    }

    /** Zend zend_execute.c FETCH_DIM_W on scalars (#6325, #4713). */
    public const SCALAR_USED_AS_ARRAY_MESSAGE = 'Cannot use a scalar value as an array';

    /**
     * True when []= / dim-write targets a scalar container (null/bool/int/float).
     */
    public static function isScalarUsedAsArray(Variable $value): bool
    {
        $resolved = $value->resolveIndirect();

        return \in_array($resolved->type, [
            Variable::TYPE_NULL,
            Variable::TYPE_BOOLEAN,
            Variable::TYPE_INTEGER,
            Variable::TYPE_FLOAT,
        ], true);
    }

    /**
     * Zend write/append [] on scalars — Error "Cannot use a scalar value as an array" (zend_execute.c, #6325).
     *
     * @deprecated Use isScalarUsedAsArray() + SCALAR_USED_AS_ARRAY_MESSAGE; kept for const-expr paths.
     */
    public static function cannotUseBracketOn(Variable $value): ?string
    {
        return self::isScalarUsedAsArray($value) ? self::SCALAR_USED_AS_ARRAY_MESSAGE : null;
    }

    private static function isExactType(Variable $value, int $constraint): bool
    {
        return $value->type === $constraint;
    }

    private static function matchesLiteralBool(Variable $value, string $literal): bool
    {
        $resolved = $value->resolveIndirect();
        if (Variable::TYPE_BOOLEAN !== $resolved->type) {
            return false;
        }

        return ('true' === $literal) === $resolved->toBool();
    }

    private static function assertLiteralBool(
        Variable $dest,
        string $literal,
        string $kind,
        bool $propertyWrite,
        int $constraint
    ): void {
        if (self::matchesLiteralBool($dest, $literal)) {
            return;
        }
        $value = $dest->resolveIndirect();
        throw self::typedSlotError($dest, $constraint, $value, $kind, $propertyWrite, $literal);
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

    private static function matchesClassTypeHint(Variable $value, string $classConstraint): bool
    {
        $resolved = $value->resolveIndirect();
        $classLc = strtolower(ltrim($classConstraint, '\\'));
        $vm = \PHPCompiler\VM::running();
        $context = $vm?->context;
        if (null !== $context) {
            $targetClass = $context->classes[$classLc] ?? null;
            if (null !== $targetClass && $targetClass->isEnum) {
                if (Variable::TYPE_ENUM_CASE === $resolved->type) {
                    return InterfaceCheck::entryIsInstanceOf(
                        $resolved->toEnumCase()->enumClass,
                        $classLc,
                        $context
                    );
                }
                if (Variable::TYPE_OBJECT === $resolved->type && EnumCaseSupport::isEnumCase($resolved->toObject())) {
                    return InterfaceCheck::entryIsInstanceOf(
                        $resolved->toObject()->class,
                        $classLc,
                        $context
                    );
                }

                return false;
            }
        }
        if (Variable::TYPE_ENUM_CASE === $resolved->type) {
            $entry = $resolved->toEnumCase()->enumClass;
            if (null === $context) {
                return strtolower($entry->name) === $classLc;
            }

            return InterfaceCheck::entryIsInstanceOf($entry, $classLc, $context);
        }
        if (Variable::TYPE_OBJECT !== $resolved->type) {
            return false;
        }
        if (null === $context) {
            return strtolower($resolved->toObject()->class->name) === $classLc;
        }
        $targetClass = $context->classes[$classLc] ?? null;
        if (null !== $targetClass && $targetClass->isInterface) {
            return InterfaceCheck::entryImplements($resolved->toObject()->class, $classLc, $context);
        }

        return InterfaceCheck::entryIsInstanceOf($resolved->toObject()->class, $classLc, $context);
    }

    private static function normalizeClassLabel(string $classConstraint): string
    {
        return ltrim($classConstraint, '\\');
    }

    private static function typedSlotError(
        Variable $target,
        int $constraint,
        Variable $value,
        string $kind,
        bool $propertyWrite,
        ?string $literalBoolType = null,
        ?string $expectedOverride = null
    ): \TypeError {
        $expected = $expectedOverride
            ?? (null !== $literalBoolType
                ? $literalBoolType
                : ($target->declaredTypeLabel ?? self::typeName($constraint)));
        if ($propertyWrite && ('Property' === $kind || 'Static variable' === $kind)) {
            return self::propertyTypeError($target, $expected, $value);
        }

        return new \TypeError(self::strictMessage($constraint, $value, $kind, $expected));
    }

    private static function propertyTypeError(
        Variable $target,
        string $expectedType,
        Variable $value
    ): \TypeError {
        if (null !== $target->functionStaticVarName && '' !== $target->functionStaticVarName) {
            return new \TypeError(sprintf(
                'Cannot assign %s to static variable $%s of type %s',
                self::valueTypeLabel($value),
                $target->functionStaticVarName,
                $expectedType
            ));
        }
        $owner = $target->objectPropertyOwner;
        $propName = $target->objectPropertyName ?? 'property';
        if (null !== $owner) {
            return new \TypeError(sprintf(
                'Cannot assign %s to property %s::$%s of type %s',
                self::valueTypeLabel($value),
                $owner->class->name,
                $propName,
                $expectedType
            ));
        }
        $classLc = $target->staticPropertyClassLc;
        if (null !== $classLc && '' !== $propName) {
            $classLabel = $classLc;
            $vm = \PHPCompiler\VM::running();
            if (null !== $vm && isset($vm->context->classes[$classLc])) {
                $classLabel = $vm->context->classes[$classLc]->name;
            }

            return new \TypeError(sprintf(
                'Cannot assign %s to property %s::$%s of type %s',
                self::valueTypeLabel($value),
                $classLabel,
                $propName,
                $expectedType
            ));
        }

        return new \TypeError(self::strictMessage(
            $target->typeConstraint ?? Variable::TYPE_INTEGER,
            $value,
            'Property',
            $expectedType
        ));
    }

    private static function strictMessage(
        int $constraint,
        Variable $value,
        string $kind = 'Argument',
        ?string $expectedOverride = null
    ): string {
        $expected = $expectedOverride ?? self::typeName($constraint);
        $given = self::valueTypeLabel($value);

        return "{$kind} must be of type {$expected}, {$given} given";
    }

    private static function valueTypeLabel(Variable $value): string
    {
        $resolved = $value->resolveIndirect();
        if (Variable::TYPE_ENUM_CASE === $resolved->type) {
            return $resolved->toEnumCase()->enumClass->name;
        }
        if (Variable::TYPE_OBJECT === $resolved->type) {
            return $resolved->toObject()->class->name;
        }

        return self::typeName($resolved->type);
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
            case Variable::TYPE_ENUM_CASE:
                return 'object';
            default:
                return 'mixed';
        }
    }
}
