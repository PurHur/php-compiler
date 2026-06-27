<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\MagicMethodDispatch;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for method_exists() (issue #1215, #4360). */
final class JitMethodExists
{
    private const OBJECT_OR_CLASS_TYPE_ERROR =
        'method_exists(): Argument #1 ($object_or_class) must be of type object|string, %s given';

    public static function invoke(Context $context, JITVariable $objectOrClass, JITVariable $methodArg): Value
    {
        $methodLiteral = JitStringArg::compileTimeLiteral($methodArg);
        if (JITVariable::TYPE_OBJECT === $objectOrClass->type) {
            return self::forObject($context, $objectOrClass, $methodArg, $methodLiteral);
        }
        if (JITVariable::TYPE_VALUE === $objectOrClass->type) {
            return self::invokeFromValueBox($context, $objectOrClass, $methodArg, $methodLiteral);
        }
        if (JITVariable::TYPE_STRING !== $objectOrClass->type) {
            throw new \LogicException('method_exists() requires object or string class name in this compiler build');
        }
        $classLiteral = JitStringArg::compileTimeLiteral($objectOrClass);
        if (null !== $classLiteral && null !== $methodLiteral) {
            return ReflectionBuiltinHelper::methodExistsLiteral($context, $classLiteral, $methodLiteral);
        }
        if (null !== $classLiteral) {
            return self::forClassLiteralRuntimeMethod($context, $classLiteral, $methodArg);
        }

        throw new \LogicException('method_exists() requires a string literal class name in this compiler build');
    }

    private static function invokeFromValueBox(
        Context $context,
        JITVariable $objectOrClass,
        JITVariable $methodArg,
        ?string $methodLiteral
    ): Value {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $objectOrClass);
        $typeField = $context->structFieldMap['__value__']['type'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $typeField)
        );
        $i8 = $context->getTypeFromString('int8');
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_OBJECT, false)
        );
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING, false)
        );

        $objectBlock = BasicBlockHelper::append($context, 'method_exists_obj');
        $notObject = BasicBlockHelper::append($context, 'method_exists_not_obj');
        $stringBlock = BasicBlockHelper::append($context, 'method_exists_str');
        $errBlock = BasicBlockHelper::append($context, 'method_exists_err');
        $mergeBlock = BasicBlockHelper::append($context, 'method_exists_merge');

        $context->builder->branchIf($isObject, $objectBlock, $notObject);

        $context->builder->positionAtEnd($objectBlock);
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $objVar = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $obj
        );
        $objResult = self::forObject($context, $objVar, $methodArg, $methodLiteral);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($notObject);
        $context->builder->branchIf($isString, $stringBlock, $errBlock);

        $context->builder->positionAtEnd($stringBlock);
        $classLiteral = JitStringArg::compileTimeLiteral($objectOrClass);
        if (null !== $classLiteral && null !== $methodLiteral) {
            $strResult = ReflectionBuiltinHelper::methodExistsLiteral(
                $context,
                $classLiteral,
                $methodLiteral
            );
        } elseif (null !== $classLiteral) {
            $strResult = self::forClassLiteralRuntimeMethod($context, $classLiteral, $methodArg);
        } else {
            $i1 = $context->getTypeFromString('int1');
            $strResult = $i1->constInt(0, false);
        }
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($errBlock);
        self::emitJitTypeErrorAndAbort($context, self::OBJECT_OR_CLASS_TYPE_ERROR, 'mixed');
        $i1 = $context->getTypeFromString('int1');
        $errResult = $i1->constInt(0, false);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($objResult->typeOf());
        $phi->addIncoming($objResult, $objectBlock);
        $phi->addIncoming($strResult, $stringBlock);
        $phi->addIncoming($errResult, $errBlock);

        return $phi;
    }

    private static function forObject(
        Context $context,
        JITVariable $objectArg,
        JITVariable $methodArg,
        ?string $methodLiteral
    ): Value {
        $objMap = $context->structFieldMap['__object__'];
        $obj = $context->helper->loadValue($objectArg);
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );
        if (null !== $methodLiteral) {
            return self::existsForClassIdLiteralMethod($context, $classId, $methodLiteral, true);
        }
        $methodStr = JitStringArg::lower($context, $methodArg, 'method_exists() method name');

        return self::existsForClassIdRuntimeMethod($context, $classId, $methodStr);
    }

    private static function forClassLiteralRuntimeMethod(
        Context $context,
        string $className,
        JITVariable $methodArg
    ): Value {
        $object = $context->type->object;
        if (!$object->hasUserDeclaredClass($className)) {
            $i1 = $context->getTypeFromString('int1');

            return $i1->constInt(0, false);
        }
        $classId = $object->lookup($className);
        $methodLiteral = JitStringArg::compileTimeLiteral($methodArg);
        if (null !== $methodLiteral) {
            return ReflectionBuiltinHelper::methodExistsLiteral($context, $className, $methodLiteral);
        }
        $methodStr = JitStringArg::lower($context, $methodArg, 'method_exists() method name');

        return self::existsForClassIdRuntimeMethodOnClass($context, $classId, $methodStr);
    }

    private static function existsForClassIdLiteralMethod(
        Context $context,
        Value $classId,
        string $method,
        bool $walkInheritance
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
            if ($object->isEnumClassId($id)) {
                $classExists = self::enumMethodExistsConst($context, $id, $method);
            } elseif ($walkInheritance) {
                $classExists = MagicMethodDispatch::hasInstanceMethod($object, $id, $method)
                    ? $i1->constInt(1, false)
                    : $i1->constInt(0, false);
            } else {
                $classExists = $object->hasMethod($id, $method)
                    ? $i1->constInt(1, false)
                    : $i1->constInt(0, false);
            }
            $exists = $context->builder->select($isClass, $classExists, $exists);
        }

        return $exists;
    }

    private static function enumMethodExistsConst(Context $context, int $classId, string $method): Value
    {
        $i1 = $context->getTypeFromString('int1');
        $methodLc = strtolower($method);
        if ('cases' === $methodLc) {
            return $i1->constInt(1, false);
        }
        if (('from' === $methodLc || 'tryfrom' === $methodLc)
            && $context->type->object->enumHasBacking($classId)) {
            return $i1->constInt(1, false);
        }

        return $i1->constInt(0, false);
    }

    private static function existsForClassIdRuntimeMethod(
        Context $context,
        Value $classId,
        Value $methodStr
    ): Value {
        return self::existsForClassIdLiteralMethodDynamic($context, $classId, $methodStr, true);
    }

    private static function existsForClassIdRuntimeMethodOnClass(
        Context $context,
        int $classId,
        Value $methodStr
    ): Value {
        $i1 = $context->getTypeFromString('int1');
        $exists = $i1->constInt(0, false);
        $object = $context->type->object;
        $methodData = self::stringDataPtr($context, $methodStr);
        $strcasecmpFn = $context->lookupFunction('strcasecmp');
        $i32 = $context->getTypeFromString('int32');

        foreach ($object->declaredMethodNames($classId) as $candidate) {
            $lit = $context->builder->load($context->constantStringFromString($candidate));
            $candidateData = self::stringDataPtr($context, $lit);
            $cmp = $context->builder->call($strcasecmpFn, $methodData, $candidateData);
            $match = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
            $exists = $context->builder->or($exists, $match);
        }

        return $exists;
    }

    private static function existsForClassIdLiteralMethodDynamic(
        Context $context,
        Value $classId,
        Value $methodStr,
        bool $walkInheritance
    ): Value {
        $i1 = $context->getTypeFromString('int1');
        $exists = $i1->constInt(0, false);
        $object = $context->type->object;
        $methodData = self::stringDataPtr($context, $methodStr);
        $strcasecmpFn = $context->lookupFunction('strcasecmp');
        $i32 = $context->getTypeFromString('int32');

        foreach ($object->allClassNamesById() as $id => $className) {
            $isClass = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $context->constantFromInteger($id, 'int64')
            );
            if ($object->isEnumClassId($id)) {
                $classExists = self::enumMethodExistsDynamic($context, $id, $methodData, $strcasecmpFn, $i32);
            } elseif ($walkInheritance) {
                $classExists = self::instanceMethodExistsDynamic($context, $id, $methodData, $strcasecmpFn, $i32);
            } else {
                $classExists = self::existsForClassIdRuntimeMethodOnClass($context, $id, $methodStr);
            }
            $exists = $context->builder->select($isClass, $classExists, $exists);
        }

        return $exists;
    }

    private static function enumMethodExistsDynamic(
        Context $context,
        int $classId,
        Value $methodData,
        Value $strcasecmpFn,
        Value $i32
    ): Value {
        $i1 = $context->getTypeFromString('int1');
        $exists = $i1->constInt(0, false);
        foreach (['cases', 'from', 'tryfrom'] as $candidate) {
            if (('from' === $candidate || 'tryfrom' === $candidate)
                && !$context->type->object->enumHasBacking($classId)) {
                continue;
            }
            $lit = $context->builder->load($context->constantStringFromString($candidate));
            $candidateData = self::stringDataPtr($context, $lit);
            $cmp = $context->builder->call($strcasecmpFn, $methodData, $candidateData);
            $match = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
            $exists = $context->builder->or($exists, $match);
        }

        return $exists;
    }

    private static function instanceMethodExistsDynamic(
        Context $context,
        int $startClassId,
        Value $methodData,
        Value $strcasecmpFn,
        Value $i32
    ): Value {
        $i1 = $context->getTypeFromString('int1');
        $object = $context->type->object;
        $exists = $i1->constInt(0, false);
        $classLc = strtolower(ltrim($object->classNameForId($startClassId), '\\'));
        $visited = [];
        for ($depth = 0; $depth < 64; ++$depth) {
            if (isset($visited[$classLc])) {
                break;
            }
            $visited[$classLc] = true;
            $id = $object->lookup($classLc);
            foreach ($object->declaredMethodNames($id) as $candidate) {
                $lit = $context->builder->load($context->constantStringFromString($candidate));
                $candidateData = self::stringDataPtr($context, $lit);
                $cmp = $context->builder->call($strcasecmpFn, $methodData, $candidateData);
                $match = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
                $exists = $context->builder->or($exists, $match);
            }
            $parentLc = $object->parentClassLc($classLc);
            if (null === $parentLc) {
                break;
            }
            $classLc = $parentLc;
        }

        return $exists;
    }

    private static function stringDataPtr(Context $context, Value $strPtr): Value
    {
        $structName = $strPtr->typeOf()->getElementType()->getName();
        $off = $context->structFieldMap[$structName]['value'];

        return $context->builder->structGep($strPtr, $off);
    }

    private static function emitJitTypeErrorAndAbort(Context $context, string $template, string $given): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, \sprintf($template, $given));
        $context->builder->call($context->lookupFunction('abort'));
    }
}
