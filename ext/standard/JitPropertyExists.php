<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for property_exists() (issue #1372). */
final class JitPropertyExists
{
    public static function invoke(Context $context, JITVariable $objectOrClass, JITVariable $propertyArg): Value
    {
        $propLiteral = JitStringArg::compileTimeLiteral($propertyArg);
        if (JITVariable::TYPE_OBJECT === $objectOrClass->type) {
            return self::forObject($context, $objectOrClass, $propLiteral);
        }
        if (JITVariable::TYPE_STRING !== $objectOrClass->type && JITVariable::TYPE_VALUE !== $objectOrClass->type) {
            throw new \LogicException('property_exists() expects an object or class name string in this compiler build');
        }
        $classLiteral = JitStringArg::compileTimeLiteral($objectOrClass);
        if (null !== $classLiteral && null !== $propLiteral) {
            return ReflectionBuiltinHelper::propertyExistsLiteral($context, $classLiteral, $propLiteral);
        }
        if (null !== $classLiteral) {
            return self::forClassLiteralRuntimeProperty($context, $classLiteral, $propertyArg);
        }

        throw new \LogicException('property_exists() requires a string literal class name in this compiler build');
    }

    private static function forObject(Context $context, JITVariable $objectArg, ?string $propLiteral): Value
    {
        if (null === $propLiteral) {
            throw new \LogicException('property_exists() on object requires a string literal property name in JIT in this compiler build');
        }
        $objMap = $context->structFieldMap['__object__'];
        $obj = $context->helper->loadValue($objectArg);
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );

        return self::existsForClassIdLiteralProperty($context, $classId, $propLiteral);
    }

    private static function forClassLiteralRuntimeProperty(
        Context $context,
        string $className,
        JITVariable $propertyArg
    ): Value {
        $object = $context->type->object;
        if (!$object->hasUserDeclaredClass($className)) {
            $i1 = $context->getTypeFromString('int1');

            return $i1->constInt(0, false);
        }
        $classId = $object->lookup($className);
        $propStr = JitStringArg::lower($context, $propertyArg, 'property_exists() property name');

        return self::existsForClassIdRuntimeProperty($context, $classId, $propStr);
    }

    private static function existsForClassIdLiteralProperty(
        Context $context,
        Value $classId,
        string $property
    ): Value {
        $i1 = $context->getTypeFromString('int1');
        $object = $context->type->object;
        $exists = $i1->constInt(0, false);
        foreach ($object->allClassNamesById() as $id => $className) {
            $isClass = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $context->constantFromInteger($id, 'int64')
            );
            $classExists = $object->hasProperty($id, $property)
                ? $i1->constInt(1, false)
                : $i1->constInt(0, false);
            $exists = $context->builder->select($isClass, $classExists, $exists);
        }

        return $exists;
    }

    private static function existsForClassIdRuntimeProperty(
        Context $context,
        int $classId,
        Value $propertyStr
    ): Value {
        $i1 = $context->getTypeFromString('int1');
        $exists = $i1->constInt(0, false);
        $object = $context->type->object;
        $propData = self::stringDataPtr($context, $propertyStr);
        $strcasecmpFn = $context->lookupFunction('strcasecmp');
        $i32 = $context->getTypeFromString('int32');

        foreach ($object->declaredPropertyNames($classId) as $candidate) {
            $lit = $context->builder->load($context->constantStringFromString($candidate));
            $candidateData = self::stringDataPtr($context, $lit);
            $cmp = $context->builder->call($strcasecmpFn, $propData, $candidateData);
            $match = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
            $exists = $context->builder->or($exists, $match);
        }

        return $exists;
    }

    private static function stringDataPtr(Context $context, Value $strPtr): Value
    {
        $structName = $strPtr->typeOf()->getElementType()->getName();
        $off = $context->structFieldMap[$structName]['value'];

        return $context->builder->structGep($strPtr, $off);
    }
}
