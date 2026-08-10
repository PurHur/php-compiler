<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Block;
use PHPCompiler\GenericArrayTypeSpec;
use PHPCompiler\ext\standard\VmMath;

/**
 * Scalar type coercion for typed parameters (issue #156) and typed properties (#169).
 */
final class TypeCheck
{
    private static ?UserParamErrorContext $paramErrorContext = null;

  /**
   * @template T
   * @param callable(): T $fn
   * @return T
   */
    public static function withParamErrorContext(?UserParamErrorContext $ctx, callable $fn)
    {
        $prev = self::$paramErrorContext;
        self::$paramErrorContext = $ctx;
        try {
            return $fn();
        } finally {
            self::$paramErrorContext = $prev;
        }
    }

    public static function currentParamErrorContext(): ?UserParamErrorContext
    {
        return self::$paramErrorContext;
    }

    public static function assertNeverParameter(Variable $argument): void
    {
        $ctx = self::$paramErrorContext;
        if (null !== $ctx) {
            $ctx->throwExpectedType('never', $argument);
        }
        throw new \TypeError(
            'Argument must be of type never, '.self::valueTypeLabel($argument).' given'
        );
    }

    public static function variadicSlotNeedsElementChecks(Block $block, int $slot): bool
    {
        return isset($block->paramNeverSlots[$slot])
            || isset($block->paramIterableSlots[$slot])
            || isset($block->paramCallableSlots[$slot])
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
     * @param list<int>|null $callArgIndexes 0-based call-site argument indexes (Zend Argument #N, #19695)
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
        ?string $intersectionDisplay = null,
        ?array $callArgIndexes = null
    ): void {
        $baseCtx = self::$paramErrorContext;
        foreach ($elements as $i => $element) {
            $check = static function () use (
                $element,
                $strict,
                $typeConstraint,
                $arraySpec,
                $intersection,
                $dnfArms,
                $context,
                $iterableElement,
                $neverElement,
                $intersectionDisplay
            ): void {
                $probe = new Variable();
                $probe->copyFrom($element);
                $resolved = $probe->resolveIndirect();
                if ($neverElement) {
                    self::assertNeverParameter($probe);

                    return;
                }
                if ($iterableElement) {
                    IterableCheck::assertParameter($probe, $context);

                    return;
                }
                if (null !== $dnfArms) {
                    DnfCheck::assertMatches($probe, $dnfArms, $context, 'Argument', null, $strict);

                    return;
                }
                if (null !== $intersection) {
                    self::assertParamIntersection($probe, $intersection, $context, $intersectionDisplay);

                    return;
                }
                if (null !== $typeConstraint) {
                    $resolved->typeConstraint = $typeConstraint;
                }
                // Coerce a probe so typeConstraint is not left on the caller's arg slot,
                // then write the coerced value back (Zend zend_verify_variadic_arg_type, #26587).
                self::coerceParameter($probe, $strict, $arraySpec);
                $element->copyFrom($probe);
            };
            if (null !== $baseCtx && null !== $callArgIndexes && isset($callArgIndexes[$i])) {
                $ctx = new UserParamErrorContext(
                    $baseCtx->functionName,
                    $callArgIndexes[$i],
                    $baseCtx->paramName,
                    $baseCtx->scriptPath,
                    $baseCtx->callSiteLine,
                    $baseCtx->omitParamName,
                );
                self::withParamErrorContext($ctx, $check);
            } else {
                $check();
            }
        }
    }

    public static function coerceParameter(Variable $dest, bool $strict, ?GenericArrayTypeSpec $arraySpec = null): void
    {
        $target = $dest->resolveIndirect();
        if (null !== $target->unionTypeConstraints) {
            self::coerceUnionValue(
                $target,
                $target->unionTypeConstraints,
                $strict,
                'Argument',
                $target->declaredTypeLabel
            );
            if (null !== $arraySpec) {
                self::assertGenericArrayShape($dest, $arraySpec, 'Argument');
            }

            return;
        }
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

    /** Set while coercing a write through `$r = &$typedProp` (#25622). */
    private static bool $assignViaTypedPropertyReference = false;

    public static function coercePropertyWrite(Variable $dest, bool $strict): void
    {
        $prevViaRef = self::$assignViaTypedPropertyReference;
        self::$assignViaTypedPropertyReference = self::destIsTypedPropertyByRefWrite($dest);
        try {
            $target = $dest->resolveIndirect();
            if (null !== $target->dnfArms) {
                return;
            }
            if (null !== $target->unionTypeConstraints) {
                self::coerceUnionValue(
                    $target,
                    $target->unionTypeConstraints,
                    $strict,
                    null !== $target->functionStaticVarName ? 'Static variable' : 'Property',
                    $target->declaredTypeLabel
                );

                return;
            }
            $kind = null !== $target->functionStaticVarName ? 'Static variable' : 'Property';
            self::coerceTypedSlot($dest, $strict, $kind, null, true);
            $resolved = $dest->resolveIndirect();
            if (null !== $resolved->genericArrayTypeSpec) {
                self::assertGenericArrayShape($resolved, $resolved->genericArrayTypeSpec, $kind);
            }
        } finally {
            self::$assignViaTypedPropertyReference = $prevViaRef;
        }
    }

    /** Expose assign-via-ref for DnfCheck property TypeErrors (#25622). */
    public static function withTypedPropertyByRefAssign(bool $viaReference, callable $fn): void
    {
        $prev = self::$assignViaTypedPropertyReference;
        self::$assignViaTypedPropertyReference = $viaReference;
        try {
            $fn();
        } finally {
            self::$assignViaTypedPropertyReference = $prev;
        }
    }

    public static function isAssignViaTypedPropertyReference(): bool
    {
        return self::$assignViaTypedPropertyReference;
    }

    /**
     * Zend: writes through `$r = &$typedProp` say "reference held by property";
     * direct `$obj->prop =` / fetch-write temps do not (#25622).
     */
    public static function destIsTypedPropertyByRefWrite(Variable $dest): bool
    {
        if ($dest->typedPropertyByRef) {
            return true;
        }
        if ($dest->propertyAssignLvalue || !$dest->isIndirect()) {
            return false;
        }
        $target = $dest->resolveIndirect();

        return (null !== $target->objectPropertyOwner && null !== $target->objectPropertyName)
            || (null !== $target->staticPropertyClassLc && null !== $target->objectPropertyName);
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
            DnfCheck::assertMatches($value, $meta->dnfArms, $context, 'Return value', null, $strict);

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
        ?string $literalBoolType = null,
        ?string $callableName = null
    ): void {
        if (null !== $literalBoolType) {
            $probe = new Variable();
            $probe->copyFrom($value);
            $probe->resolveIndirect()->literalBoolType = $literalBoolType;
            self::coerceTypedSlot($probe, true, 'Return value', $constraint, false, $callableName);

            return;
        }
        self::coerceTypedSlot($value, $strict, 'Return value', $constraint, false, $callableName);
    }

    /**
     * Weak/strict union coercion for properties, parameters, and returns (#19525).
     *
     * @param list<int> $constraints
     */
    public static function coerceUnionValue(
        Variable $target,
        array $constraints,
        bool $strict,
        string $kind,
        ?string $declaredLabel = null,
        ?string $returnCallableName = null
    ): void {
        if ([] === $constraints) {
            return;
        }
        $resolved = $target->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            if (\in_array(Variable::TYPE_NULL, $constraints, true)) {
                return;
            }

            throw self::unionTypeError($resolved, $kind, $declaredLabel, $returnCallableName);
        }
        foreach ($constraints as $constraint) {
            $trial = clone $resolved;
            try {
                self::coerceTypedSlot(
                    $trial,
                    $strict,
                    $kind,
                    $constraint,
                    'Property' === $kind || 'Static variable' === $kind,
                    $returnCallableName
                );
                $target->copyFrom($trial);

                return;
            } catch (\TypeError $e) {
                continue;
            }
        }

        throw self::unionTypeError($resolved, $kind, $declaredLabel, $returnCallableName);
    }

    private static function unionTypeError(
        Variable $value,
        string $kind,
        ?string $declaredLabel,
        ?string $returnCallableName
    ): \TypeError {
        $expected = $declaredLabel ?? 'mixed';
        if ('Property' === $kind || 'Static variable' === $kind) {
            return self::propertyTypeError($value, $expected, $value);
        }
        if ('Return value' === $kind) {
            $given = self::valueTypeLabel($value);
            $message = "Return value must be of type {$expected}, {$given} returned";
            if (null !== $returnCallableName && '' !== $returnCallableName) {
                $message = "{$returnCallableName}(): {$message}";
            }

            return new \TypeError($message);
        }
        if ('Argument' === $kind) {
            $ctx = self::$paramErrorContext;
            if (null !== $ctx) {
                return ParamTypeError::forUserCallWithExpectedType(
                    $ctx->functionName,
                    $ctx->paramIndex,
                    $ctx->paramName,
                    $expected,
                    $value,
                    $ctx->scriptPath,
                    $ctx->callSiteLine,
                    $ctx->omitParamName
                );
            }
        }

        return new \TypeError(self::strictMessage(
            Variable::TYPE_INTEGER,
            $value,
            $kind,
            $expected
        ));
    }

    /**
     * Zend typed class-constant mismatch (zend_compile.c):
     * "Cannot use {given} as value for class constant {Class}::{NAME} of type {expected}" (#28501).
     */
    public static function classConstantTypeMismatchMessage(
        string $given,
        string $expected,
        ?string $constName = null,
        ?string $className = null
    ): string {
        $qualified = null;
        if (null !== $constName && '' !== $constName) {
            if (null !== $className && '' !== $className) {
                $qualified = ltrim($className, '\\').'::'.$constName;
            } else {
                $qualified = $constName;
            }
        }
        if (null !== $qualified) {
            return "Cannot use {$given} as value for class constant {$qualified} of type {$expected}";
        }

        return "Cannot use {$given} as value for class constant of type {$expected}";
    }

    /**
     * PHP 8.3 typed class constants: strict match; int literal allowed for float (zend_compile.c, #4541).
     */
    public static function assertClassConstantValue(
        Variable $value,
        int $constraint,
        ?string $constName = null,
        ?string $className = null
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

        throw new \TypeError(self::classConstantTypeMismatchMessage(
            $given,
            $expected,
            $constName,
            $className
        ));
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
            self::assertClassConstantTypedValue($value, $typeMeta, $constName, null);
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
        ?string $constName = null,
        ?string $className = null
    ): void {
        if (null !== $typeMeta->unionTypeConstraints) {
            self::assertClassConstantUnionValue(
                $value,
                $typeMeta->unionTypeConstraints,
                $constName,
                $typeMeta->declaredTypeLabel,
                $className
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
            self::assertClassConstantValue(
                $value,
                $typeMeta->typeConstraint,
                $constName,
                $className
            );
        }
    }

    /**
     * @param list<int> $constraints
     */
    public static function assertClassConstantUnionValue(
        Variable $value,
        array $constraints,
        ?string $constName = null,
        ?string $typeLabel = null,
        ?string $className = null
    ): void {
        if ([] === $constraints) {
            return;
        }
        $target = $value->resolveIndirect();
        foreach ($constraints as $constraint) {
            $trial = new Variable();
            $trial->copyFrom($target);
            try {
                self::assertClassConstantValue($trial, $constraint, null, null);
                $value->copyFrom($trial);

                return;
            } catch (\TypeError $e) {
                continue;
            }
        }
        $expected = $typeLabel ?? 'mixed';
        $given = self::typeName($target->type);

        throw new \TypeError(self::classConstantTypeMismatchMessage(
            $given,
            $expected,
            $constName,
            $className
        ));
    }

    public static function assertVoidReturn(?Variable $value): void
    {
        if (null !== $value) {
            throw new \TypeError('A void function must not return a value');
        }
    }

    /**
     * php-src zend_verify_return_error — missing/bare return for a declared type (#26485, #26486).
     * Zend raises TypeError: "{fn}(): Return value must be of type {T}, none returned".
     */
    public static function assertNoneReturned(?string $callableName, string $expectedType): void
    {
        $message = "Return value must be of type {$expectedType}, none returned";
        if (null !== $callableName && '' !== $callableName) {
            $message = "{$callableName}(): {$message}";
        }

        throw new \TypeError($message);
    }

    /**
     * Expected-type label for zend_verify_return_error "none returned" (#26486).
     * Prefer {@see ReflectionTypeSupport::cfgTypeStringForDump} so nullable/union match Zend.
     */
    public static function expectedReturnTypeLabelForNoneReturned(Block $block): string
    {
        if (null !== $block->returnDeclaredType) {
            return ReflectionTypeSupport::cfgTypeStringForDump($block->returnDeclaredType);
        }
        if ($block->returnTypeMixed) {
            return 'mixed';
        }
        if (null !== $block->returnLiteralBoolType && '' !== $block->returnLiteralBoolType) {
            return $block->returnLiteralBoolType;
        }
        if (null !== $block->returnDeclaredTypeLabel && '' !== $block->returnDeclaredTypeLabel) {
            return ltrim($block->returnDeclaredTypeLabel, '\\');
        }
        if (null !== $block->returnDnfConstraints) {
            return \PHPCompiler\DnfType::zendTypeErrorLabel(
                \PHPCompiler\DnfType::formatUnionType($block->returnDnfConstraints)
            );
        }
        if (null !== $block->returnClassConstraint && '' !== $block->returnClassConstraint) {
            return ltrim($block->returnClassConstraint, '\\');
        }
        if (null !== $block->returnTypeConstraint) {
            return self::typeNameForConstraint($block->returnTypeConstraint, $block->returnLiteralBoolType);
        }
        if ($block->returnTypeStatic) {
            return 'static';
        }

        return 'mixed';
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

    private static function coerceTypedSlot(
        Variable $dest,
        bool $strict,
        string $kind,
        ?int $constraint = null,
        bool $propertyWrite = false,
        ?string $returnCallableName = null
    ): void {
        $target = $dest->resolveIndirect();
        $constraint ??= $target->typeConstraint;
        if (null === $constraint) {
            return;
        }
        if (null !== $target->literalBoolType) {
            self::assertLiteralBool(
                $dest,
                $target->literalBoolType,
                $kind,
                $propertyWrite,
                $constraint,
                $returnCallableName
            );

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

            throw self::typedSlotError($target, $constraint, $value, $kind, $propertyWrite, null, $expected, $returnCallableName);
        }
        if ($strict) {
            if (self::isExactType($value, $constraint)) {
                return;
            }
            // Zend zend_verify_scalar_type_hint: int→float widening is allowed under
            // strict_types (params, returns, typed properties) — #28615.
            if (self::widenStrictIntToFloat($target, $constraint, $value)) {
                return;
            }

            throw self::typedSlotError($target, $constraint, $value, $kind, $propertyWrite, null, null, $returnCallableName);
        }
        if (self::isExactType($value, $constraint)) {
            return;
        }
        try {
            self::weakCoerceInPlace($target, $constraint, $value, $kind, $propertyWrite);
        } catch (\TypeError $e) {
            throw self::typedSlotError($target, $constraint, $value, $kind, $propertyWrite, null, null, $returnCallableName);
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
        $resolved = $value->resolveIndirect();
        if (self::isExactType($resolved, $constraint)) {
            return true;
        }

        // Call-site strict check: int is accepted for float (widened at ARG_RECV) — #28615.
        return Variable::TYPE_FLOAT === $constraint && Variable::TYPE_INTEGER === $resolved->type;
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

    /** Zend zend_execute.c empty-dim append on string (#22651). */
    public const STRING_APPEND_UNSUPPORTED_MESSAGE = '[] operator not supported for strings';

    /**
     * Zend 8.1+ E_DEPRECATED when FETCH_DIM_W / []= promotes false→array (zend_execute.c, #22828).
     *
     * Null/undefined auto-vivify silently; only false emits this notice.
     */
    public const FALSE_TO_ARRAY_DEPRECATED_MESSAGE = 'Automatic conversion of false to array is deprecated';

    /**
     * True when FETCH_DIM_W / []= must auto-vivify an empty array (zend_execute.c, #21992, #22650).
     *
     * Zend promotes null, undefined, and false containers; true/int/float still Error
     * ({@see isScalarUsedAsArray()}).
     */
    public static function isNullContainerForDimAutovivify(Variable $value): bool
    {
        $resolved = $value->resolveIndirect();

        if (
            Variable::TYPE_NULL === $resolved->type
            || Variable::TYPE_UNDEFINED === $resolved->type
        ) {
            return true;
        }

        // Legacy Zend: false→[] on dim write, same as null (zend_execute.c / #22650).
        return self::isFalseContainerForDimAutovivify($resolved);
    }

    /**
     * True when the dim-write container is boolean false (zend_execute.c / #22828).
     *
     * Callers that auto-vivify must emit {@see FALSE_TO_ARRAY_DEPRECATED_MESSAGE} first.
     */
    public static function isFalseContainerForDimAutovivify(Variable $value): bool
    {
        $resolved = $value->resolveIndirect();

        return Variable::TYPE_BOOLEAN === $resolved->type && !$resolved->toBool();
    }

    /**
     * True when []= / dim-write targets a true scalar (true/int/float).
     *
     * Null/undefined/false are not included — Zend auto-vivifies them (#21992, #22650); see
     * {@see isNullContainerForDimAutovivify()}.
     */
    public static function isScalarUsedAsArray(Variable $value): bool
    {
        $resolved = $value->resolveIndirect();

        if (Variable::TYPE_BOOLEAN === $resolved->type) {
            return $resolved->toBool();
        }

        return \in_array($resolved->type, [
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

    /**
     * Under strict_types, Zend still widens int→float (zend_execute.h / zend_types.h, #28615).
     *
     * @return bool true when the slot was widened in place
     */
    private static function widenStrictIntToFloat(Variable $dest, int $constraint, Variable $value): bool
    {
        if (Variable::TYPE_FLOAT !== $constraint || Variable::TYPE_INTEGER !== $value->type) {
            return false;
        }
        $dest->float((float) $value->toInt());

        return true;
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
        int $constraint,
        ?string $returnCallableName = null
    ): void {
        if (self::matchesLiteralBool($dest, $literal)) {
            return;
        }
        $value = $dest->resolveIndirect();
        throw self::typedSlotError(
            $dest,
            $constraint,
            $value,
            $kind,
            $propertyWrite,
            $literal,
            null,
            $returnCallableName
        );
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
                // Zend weak mode: null is not a valid string (zend_API.c / zend_execute.c, #19695).
                // int/float/bool still coerce via toString(); arrays stay TypeError.
                // Objects: Stringable / public __toString coerce (zend_verify_arg_type, #22548).
                if (
                    Variable::TYPE_NULL === $value->type
                    || Variable::TYPE_ARRAY === $value->type
                ) {
                    throw new \TypeError(self::strictMessage($constraint, $value, $kind));
                }
                if (Variable::TYPE_OBJECT === $value->type) {
                    $coerced = self::tryCoerceStringableObjectToString($value);
                    if (null === $coerced) {
                        throw new \TypeError(self::strictMessage($constraint, $value, $kind));
                    }
                    $dest->string($coerced);

                    return;
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

    /**
     * Weak string type check: coerce Stringable (explicit or implicit public __toString).
     *
     * php-src: Zend/zend_execute_API.c zend_verify_arg_type / zend_parse_arg_impl
     * (#22548; related #7198). Non-Stringable objects stay TypeError.
     */
    private static function tryCoerceStringableObjectToString(Variable $value): ?string
    {
        $resolved = $value->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $resolved->type) {
            return null;
        }
        $object = $resolved->toObject();
        if (EnumCaseSupport::isEnumCase($object) || ResourceSupport::isResourceObject($object)) {
            return null;
        }
        $vm = \PHPCompiler\VM::running();
        if (null === $vm) {
            return null;
        }
        if (!InterfaceCheck::entryIsInstanceOf(
            $object->class,
            StringableSupport::INTERFACE_LC,
            $vm->context
        )) {
            return null;
        }

        return $vm->coerceVariableToString($resolved);
    }

    private static function coerceToInt(Variable $value, string $kind = 'Argument'): int
    {
        switch ($value->type) {
            case Variable::TYPE_INTEGER:
                return $value->toInt();
            case Variable::TYPE_BOOLEAN:
                return $value->toBool() ? 1 : 0;
            case Variable::TYPE_FLOAT:
                // Zend weak mode: truncate toward zero; precision loss → E_DEPRECATED
                // (zend_dval_to_lval_safe / zend_operators.c, #23533).
                // Non-finite (INF/NAN) → TypeError — not the cast path (#27925).
                // Untyped bitwise/dim E_DEPRECATED for INF/NAN is #27926 (VmMath / dim paths).
                $float = $value->toFloat();
                if (!\is_finite($float)) {
                    self::throwCoerceKindError(
                        $kind,
                        'int',
                        'float given',
                        Variable::TYPE_INTEGER,
                        $value
                    );
                }
                $vm = \PHPCompiler\VM::running();
                if (null !== $vm) {
                    VmMath::warnFloatToIntPrecisionLoss(
                        $float,
                        $vm->context,
                        $vm->currentExecutingFrame()
                    );
                }

                return VmMath::floatToZendLong($float);
            case Variable::TYPE_STRING:
                $s = $value->toString();
                if (!is_numeric($s)) {
                    self::throwCoerceKindError($kind, 'int', 'string given', Variable::TYPE_INTEGER, $value);
                }

                return (int) (float) $s;
        }
        self::throwCoerceKindError($kind, 'int', self::valueTypeLabel($value).' given', Variable::TYPE_INTEGER, $value);
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
                    self::throwCoerceKindError($kind, 'float', 'string given', Variable::TYPE_FLOAT, $value);
                }

                return (float) $s;
        }
        self::throwCoerceKindError($kind, 'float', self::valueTypeLabel($value).' given', Variable::TYPE_FLOAT, $value);
    }

    private static function coerceToBool(Variable $value, string $kind = 'Argument'): bool
    {
        // php-src convert_to_boolean / zend_is_true (Zend/zend_operators.c) — not
        // FILTER_VALIDATE_BOOLEAN. Weak bool params/properties accept any string except
        // "" / "0" (#29860); null/array/object stay TypeError.
        switch ($value->type) {
            case Variable::TYPE_BOOLEAN:
                return $value->toBool();
            case Variable::TYPE_INTEGER:
                return 0 !== $value->toInt();
            case Variable::TYPE_FLOAT:
                return 0.0 !== $value->toFloat();
            case Variable::TYPE_STRING:
                $s = $value->toString();

                return '' !== $s && '0' !== $s;
        }
        self::throwCoerceKindError($kind, 'bool', self::valueTypeLabel($value).' given', Variable::TYPE_BOOLEAN, $value);
    }

    private static function throwCoerceKindError(
        string $kind,
        string $expected,
        string $given,
        int $constraint,
        Variable $value
    ): void {
        if ('Argument' === $kind) {
            $ctx = self::$paramErrorContext;
            if (null !== $ctx) {
                $ctx->throwExpectedType($expected, $value);
            }
        }
        throw new \TypeError("{$kind} must be of type {$expected}, {$given}");
    }

    private static function matchesClassTypeHint(Variable $value, string $classConstraint): bool
    {
        $resolved = $value->resolveIndirect();
        $classLc = strtolower(ltrim($classConstraint, '\\'));
        // Zend IS_OBJECT return/param check accepts any object incl. anonymous classes (#11173, zend_execute.c).
        if ('object' === $classLc) {
            return Variable::TYPE_OBJECT === $resolved->type
                || Variable::TYPE_ENUM_CASE === $resolved->type;
        }
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
        ?string $expectedOverride = null,
        ?string $returnCallableName = null
    ): \TypeError {
        $expected = $expectedOverride
            ?? (null !== $literalBoolType
                ? $literalBoolType
                : ($target->declaredTypeLabel ?? self::typeName($constraint)));
        if ($propertyWrite && ('Property' === $kind || 'Static variable' === $kind)) {
            return self::propertyTypeError($target, $expected, $value);
        }
        if ('Argument' === $kind) {
            $ctx = self::$paramErrorContext;
            if (null !== $ctx) {
                if (null !== $literalBoolType) {
                    return ParamTypeError::forUserCall(
                        $ctx->functionName,
                        $ctx->paramIndex,
                        $ctx->paramName,
                        $constraint,
                        $value,
                        $ctx->scriptPath,
                        $ctx->callSiteLine,
                        $literalBoolType,
                        $ctx->omitParamName
                    );
                }

                return ParamTypeError::forUserCallWithExpectedType(
                    $ctx->functionName,
                    $ctx->paramIndex,
                    $ctx->paramName,
                    $expected,
                    $value,
                    $ctx->scriptPath,
                    $ctx->callSiteLine,
                    $ctx->omitParamName
                );
            }
        }

        if ('Return value' === $kind) {
            $given = self::valueTypeLabel($value);
            $message = "Return value must be of type {$expected}, {$given} returned";
            if (null !== $returnCallableName && '' !== $returnCallableName) {
                $message = "{$returnCallableName}(): {$message}";
            }

            return new \TypeError($message);
        }

        return new \TypeError(self::strictMessage($constraint, $value, $kind, $expected));
    }

    private static function propertyTypeError(
        Variable $target,
        string $expectedType,
        Variable $value
    ): \TypeError {
        $expectedType = \PHPCompiler\DnfType::zendTypeErrorLabel($expectedType);
        if (null !== $target->functionStaticVarName && '' !== $target->functionStaticVarName) {
            return new \TypeError(sprintf(
                'Cannot assign %s to static variable $%s of type %s',
                self::valueTypeLabel($value),
                $target->functionStaticVarName,
                $expectedType
            ));
        }
        $propPhrase = self::$assignViaTypedPropertyReference
            ? 'reference held by property'
            : 'property';
        $owner = $target->objectPropertyOwner;
        $propName = $target->objectPropertyName ?? 'property';
        if (null !== $owner) {
            return new \TypeError(sprintf(
                'Cannot assign %s to %s %s::$%s of type %s',
                self::valueTypeLabel($value),
                $propPhrase,
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
                'Cannot assign %s to %s %s::$%s of type %s',
                self::valueTypeLabel($value),
                $propPhrase,
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
        // zend_execute.c — bool actuals print true/false, not bool (#29097).
        return EnumCaseSupport::typeNameForTypeErrorActual($value);
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
