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
}
