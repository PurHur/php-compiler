<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;

/**
 * Backed enum case singleton objects (#3518, Zend zend_enum.c parity).
 */
final class EnumCaseSupport
{
    public static function isEnumCase(ObjectEntry $object): bool
    {
        return $object->isEnumCase;
    }

    public static function createCase(ClassEntry $enum, string $caseName, Variable $backedValue): Variable
    {
        if (!$enum->isEnum) {
            throw new \LogicException('createCase requires an enum ClassEntry');
        }
        $object = new ObjectEntry($enum);
        $object->isEnumCase = true;
        $object->enumCaseName = $caseName;
        $object->enumCaseValue = clone $backedValue;
        $object->constructed = true;

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        return $var;
    }

    public static function getProperty(ObjectEntry $object, string $name): Variable
    {
        if (!$object->isEnumCase) {
            throw new \LogicException('getProperty called on non-enum-case object');
        }
        $result = new Variable();
        $lc = strtolower($name);
        if ('name' === $lc) {
            $result->string($object->enumCaseName ?? '');

            return $result;
        }
        if ('value' === $lc) {
            if (null === $object->enumCaseValue) {
                throw new \LogicException('Cannot read value on a unit enum case');
            }
            $result->copyFrom($object->enumCaseValue);

            return $result;
        }
        throw new \LogicException("Undefined property: {$name}");
    }

    /** Zend rejects implicit string conversion of enum case objects (#4819, #5508). */
    public static function toString(ObjectEntry $object): string
    {
        if (!$object->isEnumCase) {
            throw new \LogicException('toString called on non-enum-case object');
        }

        throw new \Error("Object of class {$object->class->name} could not be converted to string");
    }

    /**
     * Zend {@see zend_compare_enum()} (#4554).
     *
     * Identical case singleton: 0. Different cases (same enum): 1. Different enums: 1.
     */
    public static function compareSpaceship(ObjectEntry $left, ObjectEntry $right): int
    {
        if (!$left->isEnumCase || !$right->isEnumCase) {
            throw new \LogicException('compareSpaceship requires enum case objects');
        }
        if ($left === $right) {
            return 0;
        }
        if ($left->class !== $right->class) {
            return 1;
        }
        if (0 === strcasecmp($left->enumCaseName ?? '', $right->enumCaseName ?? '')) {
            return 0;
        }

        return 1;
    }

    /** @see EnumCaseEntry spaceship for TYPE_ENUM_CASE operands (#4554). */
    public static function compareEnumCaseEntrySpaceship(EnumCaseEntry $left, EnumCaseEntry $right): int
    {
        if ($left->enumClass !== $right->enumClass) {
            return 1;
        }
        if ($left->caseName === $right->caseName) {
            return 0;
        }

        return 1;
    }

    /**
     * Zend {@see zend_compare()} for enum case objects (#4700).
     *
     * Same enum + same declared case name: equal. Different cases or enums: not equal.
     */
    public static function compareEquals(ObjectEntry $left, ObjectEntry $right): bool
    {
        if (!$left->isEnumCase || !$right->isEnumCase) {
            throw new \LogicException('compareEquals requires enum case objects');
        }
        if ($left->class !== $right->class) {
            return false;
        }

        return ($left->enumCaseName ?? '') === ($right->enumCaseName ?? '');
    }

    /**
     * instanceof / is_a on enum case operands (#5548, zend_enum.c).
     *
     * @return bool|null true/false when handled; null to fall through to ordinary object checks
     */
    public static function valueMatchesInstanceOfClassName(
        Variable $value,
        string $className,
        Context $context
    ): ?bool {
        $entry = self::entryForInstanceOfCheck($value);
        if (null !== $entry) {
            $className = strtolower(ltrim($className, '\\'));

            return InterfaceCheck::entryIsInstanceOf($entry, $className, $context)
                || InterfaceCheck::entryImplements($entry, $className, $context);
        }
        $classLc = strtolower(ltrim($className, '\\'));
        $target = $context->classes[$classLc] ?? null;
        if (null !== $target && self::scalarIsLegacyEnumCaseForClass($value, $target)) {
            return true;
        }

        return null;
    }

    /**
     * Zend zend_hash illegal offset — enum cases cannot be array keys (#5594, zend_hash.c).
     */
    public static function rejectIllegalArrayOffset(Variable $index, string $message = 'Illegal offset type'): void
    {
        $index = $index->resolveIndirect();
        if (Variable::TYPE_ENUM_CASE === $index->type) {
            throw new \TypeError($message);
        }
        if (Variable::TYPE_OBJECT === $index->type && self::isEnumCase($index->toObject())) {
            throw new \TypeError($message);
        }
    }

    public static function entryForInstanceOfCheck(Variable $value): ?ClassEntry
    {
        $value = $value->resolveIndirect();
        if (Variable::TYPE_ENUM_CASE === $value->type) {
            return $value->toEnumCase()->enumClass;
        }
        if (Variable::TYPE_OBJECT === $value->type && self::isEnumCase($value->toObject())) {
            return $value->toObject()->class;
        }

        return null;
    }

    /**
     * Backing scalar that stands in for a case when constants were materialized as scalars (#5514).
     */
    public static function scalarIsLegacyEnumCaseForClass(Variable $value, ClassEntry $enum): bool
    {
        if (!$enum->isEnum || null === $enum->backedType) {
            return false;
        }
        $value = $value->resolveIndirect();
        if (!$value->is(Variable::TYPE_INTEGER) && !$value->is(Variable::TYPE_STRING)) {
            return false;
        }
        $match = BackedEnum::caseForValue($enum, $value);
        if (null === $match) {
            return false;
        }
        $caseLc = strtolower($match->caseName);
        if (!isset($enum->constants[$caseLc])) {
            return false;
        }
        $stored = $enum->constants[$caseLc]->resolveIndirect();
        if (Variable::TYPE_OBJECT === $stored->type && self::isEnumCase($stored->toObject())) {
            return false;
        }

        return $stored->is(Variable::TYPE_INTEGER) || $stored->is(Variable::TYPE_STRING);
    }

    /**
     * Ordering for min()/max() on enum case operands (php-src ext/standard/array.c php_min_max).
     *
     * Backed enums: compare backing scalars. Unit enums: declaration order in enumCases.
     *
     * @return int spaceship (-1, 0, 1)
     */
    public static function compareEnumCasesForMinMax(Variable $left, Variable $right): int
    {
        [$leftClass, $leftName] = self::resolveEnumCaseIdentity($left);
        [$rightClass, $rightName] = self::resolveEnumCaseIdentity($right);
        if (null === $leftClass || null === $rightClass || $leftClass !== $rightClass) {
            throw new \LogicException('compareEnumCasesForMinMax requires same-enum case operands');
        }
        if (null !== $leftClass->backedType) {
            $leftBacking = self::backingValueForMinMax($left);
            $rightBacking = self::backingValueForMinMax($right);

            return Variable::spaceshipCompare($leftBacking, $rightBacking);
        }

        return self::enumCaseDeclarationOrdinal($leftClass, $leftName)
            <=> self::enumCaseDeclarationOrdinal($rightClass, $rightName);
    }

    /**
     * Normalize operand to a canonical enum case variable when possible (#5570).
     *
     * @param ClassEntry|null $expectedEnum when set, scalars are resolved only for this enum
     */
    public static function normalizeEnumCaseOperand(
        Variable $value,
        Context $context,
        ?ClassEntry $expectedEnum = null
    ): ?Variable {
        $value = $value->resolveIndirect();
        if (self::isEnumCaseVariable($value)) {
            return $value;
        }
        if (null === $expectedEnum || null === $expectedEnum->backedType) {
            return null;
        }
        $match = BackedEnum::caseForValue($expectedEnum, $value);
        if (null === $match) {
            return null;
        }

        return BackedEnum::canonicalCaseVariable($expectedEnum, $match->caseName) ?? self::enumCaseEntryToVariable($match);
    }

    public static function isEnumCaseVariable(Variable $value): bool
    {
        $value = $value->resolveIndirect();
        if (Variable::TYPE_ENUM_CASE === $value->type) {
            return true;
        }

        return Variable::TYPE_OBJECT === $value->type && self::isEnumCase($value->toObject());
    }

    public static function enumClassForCaseVariable(Variable $value): ?ClassEntry
    {
        $value = $value->resolveIndirect();
        if (Variable::TYPE_ENUM_CASE === $value->type) {
            return $value->toEnumCase()->enumClass;
        }
        if (Variable::TYPE_OBJECT === $value->type && self::isEnumCase($value->toObject())) {
            return $value->toObject()->class;
        }

        return null;
    }

    private static function enumCaseEntryToVariable(EnumCaseEntry $entry): Variable
    {
        return self::createCase($entry->enumClass, $entry->caseName, $entry->backingValue);
    }

    private static function backingValueForMinMax(Variable $value): Variable
    {
        $value = $value->resolveIndirect();
        if (Variable::TYPE_ENUM_CASE === $value->type) {
            $backing = new Variable();
            $backing->copyFrom($value->toEnumCase()->backingValue);

            return $backing;
        }
        if (Variable::TYPE_OBJECT === $value->type && self::isEnumCase($value->toObject())) {
            $object = $value->toObject();
            if (null === $object->enumCaseValue) {
                throw new \LogicException('Backed enum case object missing backing value');
            }
            $backing = new Variable();
            $backing->copyFrom($object->enumCaseValue);

            return $backing;
        }

        throw new \LogicException('backingValueForMinMax requires enum case variable');
    }

    private static function enumCaseDeclarationOrdinal(ClassEntry $enum, string $caseName): int
    {
        foreach ($enum->enumCases as $index => $case) {
            if ($case['name'] === $caseName) {
                return $index;
            }
        }

        return -1;
    }

    /** Loose/identical == for TYPE_OBJECT / TYPE_ENUM_CASE enum operands (#4700). */
    public static function enumCaseVariablesEqual(Variable $left, Variable $right): bool
    {
        [$leftClass, $leftName] = self::resolveEnumCaseIdentity($left);
        [$rightClass, $rightName] = self::resolveEnumCaseIdentity($right);
        if (null === $leftClass || null === $rightClass) {
            return false;
        }

        return $leftClass === $rightClass && $leftName === $rightName;
    }

    /**
     * @return array{0: ?ClassEntry, 1: string}
     */
    private static function resolveEnumCaseIdentity(Variable $var): array
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_ENUM_CASE === $var->type) {
            $entry = $var->toEnumCase();

            return [$entry->enumClass, $entry->caseName];
        }
        if (Variable::TYPE_OBJECT === $var->type && self::isEnumCase($var->toObject())) {
            $object = $var->toObject();

            return [$object->class, $object->enumCaseName ?? ''];
        }

        return [null, ''];
    }

    /**
     * Zend {@see zend_clone_obj} rejection for enum cases (#3554, #5535).
     *
     * When enum constants were materialized as backing scalars (#5514 regression),
     * clone still must throw catchable Error, not LogicException.
     */
    public static function uncloneableEnumClassForClone(Variable $src, ?Context $context = null): ?string
    {
        $src = $src->resolveIndirect();
        if (Variable::TYPE_ENUM_CASE === $src->type) {
            return $src->toEnumCase()->enumClass->name;
        }
        if (Variable::TYPE_OBJECT === $src->type && self::isEnumCase($src->toObject())) {
            return $src->toObject()->class->name;
        }
        if (null === $context) {
            return null;
        }
        if (!$src->is(Variable::TYPE_INTEGER) && !$src->is(Variable::TYPE_STRING)) {
            return null;
        }
        foreach ($context->classes as $entry) {
            if (!$entry->isEnum || null === $entry->backedType) {
                continue;
            }
            $match = BackedEnum::caseForValue($entry, $src);
            if (null === $match) {
                continue;
            }
            $caseLc = strtolower($match->caseName);
            if (!isset($entry->constants[$caseLc])) {
                continue;
            }
            $stored = $entry->constants[$caseLc]->resolveIndirect();
            if (Variable::TYPE_OBJECT === $stored->type && self::isEnumCase($stored->toObject())) {
                continue;
            }
            if ($stored->is(Variable::TYPE_INTEGER) || $stored->is(Variable::TYPE_STRING)) {
                return $entry->name;
            }
        }

        return null;
    }

    /**
     * Zend scalar (int) cast on enum case operands — backing coerce + E_WARNING (#5653, zend_operators.c).
     *
     * @return int|null backing int, 1 for unit enum, or null when $value is not an enum case
     */
    public static function tryCastToInt(Variable $value, ?Context $context = null, ?Frame $frame = null): ?int
    {
        $enumClass = self::enumClassForCaseVariable($value);
        if (null === $enumClass) {
            return null;
        }
        self::emitScalarCastWarning($context, $frame, $enumClass->name, 'int');
        $backing = self::backingValueForScalarCast($value);
        if (null === $backing) {
            return 1;
        }

        return self::coerceBackingToInt($backing);
    }

    /**
     * Zend scalar (float) cast on enum case operands (#5623 parity, zend_operators.c).
     *
     * @return float|null backing float, 1.0 for unit enum, or null when $value is not an enum case
     */
    public static function tryCastToFloat(Variable $value, ?Context $context = null, ?Frame $frame = null): ?float
    {
        $enumClass = self::enumClassForCaseVariable($value);
        if (null === $enumClass) {
            return null;
        }
        self::emitScalarCastWarning($context, $frame, $enumClass->name, 'float');
        $backing = self::backingValueForScalarCast($value);
        if (null === $backing) {
            return 1.0;
        }

        return self::coerceBackingToFloat($backing);
    }

    private static function backingValueForScalarCast(Variable $value): ?Variable
    {
        $value = $value->resolveIndirect();
        if (Variable::TYPE_ENUM_CASE === $value->type) {
            $entry = $value->toEnumCase();
            if (null === $entry->enumClass->backedType) {
                return null;
            }
            $backing = new Variable();
            $backing->copyFrom($entry->backingValue);

            return $backing;
        }
        if (Variable::TYPE_OBJECT === $value->type && self::isEnumCase($value->toObject())) {
            $object = $value->toObject();
            if (null === $object->class->backedType || null === $object->enumCaseValue) {
                return null;
            }
            $backing = new Variable();
            $backing->copyFrom($object->enumCaseValue);

            return $backing;
        }

        return null;
    }

    private static function coerceBackingToInt(Variable $backing): int
    {
        $backing = $backing->resolveIndirect();
        if (Variable::TYPE_INTEGER === $backing->type) {
            return $backing->toInt();
        }
        if (Variable::TYPE_FLOAT === $backing->type) {
            return (int) $backing->toFloat();
        }
        if (Variable::TYPE_STRING === $backing->type) {
            return (int) $backing->toString();
        }

        throw new \LogicException('Backed enum case value must be int, float, or string');
    }

    private static function coerceBackingToFloat(Variable $backing): float
    {
        $backing = $backing->resolveIndirect();
        if (Variable::TYPE_INTEGER === $backing->type) {
            return (float) $backing->toInt();
        }
        if (Variable::TYPE_FLOAT === $backing->type) {
            return $backing->toFloat();
        }
        if (Variable::TYPE_STRING === $backing->type) {
            return (float) $backing->toString();
        }

        throw new \LogicException('Backed enum case value must be int, float, or string');
    }

    private static function emitScalarCastWarning(
        ?Context $context,
        ?Frame $frame,
        string $className,
        string $kind
    ): void {
        if (null === $context) {
            return;
        }
        $message = 'int' === $kind
            ? "Object of class {$className} could not be converted to int"
            : "Object of class {$className} could not be converted to float";
        $context->errors->triggerError(
            $message,
            ErrorReporter::E_WARNING,
            null !== $frame && '' !== ($frame->scriptPath ?? '') ? $frame->scriptPath : null,
            $context,
            $frame
        );
    }
}
