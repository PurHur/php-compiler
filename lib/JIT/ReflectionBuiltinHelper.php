<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM helpers for reflection / introspection builtins (#1214–#1219).
 */
final class ReflectionBuiltinHelper
{
    private static function objectBuiltin(Context $context): Object_
    {
        return $context->type->object;
    }

    public static function requireCompileTimeClassName(Context $context, Variable $arg, string $label): string
    {
        $name = JitStringArg::compileTimeLiteral($arg);
        if (null === $name) {
            throw new \LogicException("{$label} must be a string literal in this compiler build");
        }

        return $name;
    }

    public static function classExistsLiteral(Context $context, string $className): Value
    {
        $lc = strtolower($className);
        $exists = self::objectBuiltin($context)->hasUserDeclaredClass($className)
            || isset($context->runtime->vmContext->classes[$lc]);
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt($exists ? 1 : 0, false);
    }

    public static function enumExistsLiteral(Context $context, string $enumName): Value
    {
        $lc = strtolower($enumName);
        $exists = self::objectBuiltin($context)->hasUserDeclaredEnum($enumName)
            || (null !== $context->runtime->vmContext && isset($context->runtime->vmContext->enums[$lc]));
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt($exists ? 1 : 0, false);
    }

    public static function functionExistsLiteral(Context $context, string $functionName): Value
    {
        $lc = strtolower($functionName);
        $exists = isset($context->runtime->vmContext->functions[$lc])
            || isset($context->functions[$lc]);
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt($exists ? 1 : 0, false);
    }

    public static function methodExistsLiteral(Context $context, string $className, string $method): Value
    {
        $object = self::objectBuiltin($context);
        if (!$object->hasUserDeclaredClass($className)) {
            $i1 = $context->getTypeFromString('int1');

            return $i1->constInt(0, false);
        }
        $classId = $object->lookup($className);
        $exists = $object->hasMethod($classId, $method);
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt($exists ? 1 : 0, false);
    }

    public static function propertyExistsLiteral(Context $context, string $className, string $property): Value
    {
        $object = self::objectBuiltin($context);
        if (!$object->hasUserDeclaredClass($className)) {
            $i1 = $context->getTypeFromString('int1');

            return $i1->constInt(0, false);
        }
        $classId = $object->lookup($className);
        $exists = $object->hasProperty($classId, $property);
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt($exists ? 1 : 0, false);
    }

    public static function emitInstanceOf(Context $context, Variable $value, string $className): Variable
    {
        return self::objectBuiltin($context)->emitInstanceOf($value, $className);
    }

    public static function classIsInstanceOfLiteral(Context $context, string $childName, string $parentName): Value
    {
        $match = self::objectBuiltin($context)->classIsInstanceOf($childName, $parentName);
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt($match ? 1 : 0, false);
    }

    public static function classIsSubclassOfLiteral(Context $context, string $childName, string $parentName): Value
    {
        $match = self::objectBuiltin($context)->classIsSubclassOf($childName, $parentName);
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt($match ? 1 : 0, false);
    }

    public static function getClassName(Context $context, Variable $object): Value
    {
        if (Variable::TYPE_OBJECT !== $object->type && Variable::TYPE_VALUE !== $object->type) {
            throw new \LogicException('get_class() argument must be an object in this compiler build');
        }
        $objBuiltin = self::objectBuiltin($context);
        $objMap = $context->structFieldMap['__object__'];
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $falseVal = $context->getTypeFromString('int1')->constInt(0, false);

        if (Variable::TYPE_OBJECT === $object->type) {
            $obj = $context->helper->loadValue($object);
            $classId = $context->builder->load(
                $context->builder->structGep($obj, $objMap['class_id'])
            );

            return self::classNameFromId($context, $classId);
        }

        $valuePtr = JitValueBox::valuePtrFromVariable($context, $object);
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $objType = $context->getTypeFromString('__object__*');
        $isObject = $context->builder->icmp(
            Builder::INT_NE,
            $obj,
            $objType->constNull()
        );
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );
        $nameWhenObject = self::classNameFromId($context, $classId);
        $falseStr = $context->builder->load($context->constantStringFromString(''));

        return $context->builder->select($isObject, $nameWhenObject, $falseStr);
    }

    /**
     * get_parent_class() — no class extends yet; always false (issue #1218).
     */
    public static function getParentClassLiteral(Context $context): Value
    {
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt(0, false);
    }

    private static function classNameFromId(Context $context, Value $classId): Value
    {
        $names = self::objectBuiltin($context)->allClassNamesById();
        $strPtr = $context->getTypeFromString('__string__*');
        $result = $context->builder->load($context->constantStringFromString(''));
        foreach ($names as $id => $name) {
            $expected = $context->constantFromInteger($id, 'int64');
            $isId = $context->builder->icmp(Builder::INT_EQ, $classId, $expected);
            $candidate = $context->builder->load($context->constantStringFromString($name));
            $result = $context->builder->select($isId, $candidate, $result);
        }

        return $result;
    }
}
