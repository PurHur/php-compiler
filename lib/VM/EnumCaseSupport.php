<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

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

    public static function toString(ObjectEntry $object): string
    {
        if (!$object->isEnumCase) {
            throw new \LogicException('toString called on non-enum-case object');
        }
        if (null === $object->enumCaseValue) {
            $className = $object->class->name;
            throw new \Error("Object of class {$className} could not be converted to string");
        }
        $value = $object->enumCaseValue;
        switch ($value->type) {
            case Variable::TYPE_STRING:
                return $value->toString();
            case Variable::TYPE_INTEGER:
                return (string) $value->toInt();
            case Variable::TYPE_FLOAT:
                return (string) $value->toFloat();
            default:
                $className = $object->class->name;
                throw new \Error("Object of class {$className} could not be converted to string");
        }
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
}
