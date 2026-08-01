<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringMethodExists;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\MagicMethodDispatch;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** JIT/AOT helper for method_exists() via MethodExistsJitHelper PHP (#1215, #4360, #16479). */
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
            self::emitTypeErrorAndAbort($context, self::scalarTypeError($objectOrClass->type));
            $i1 = $context->getTypeFromString('int1');

            return $i1->constInt(0, false);
        }
        $classLiteral = JitStringArg::compileTimeLiteral($objectOrClass);
        if (null !== $classLiteral) {
            // Runtime helper — autoloads like zend_lookup_class (#26407). Compile-time
            // fold would skip registered autoloaders for not-yet-loaded class strings.
            return self::routeThroughPhpHelper($context, $objectOrClass, $methodArg);
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
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NULL, false)
        );
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

        $nullBlock = BasicBlockHelper::append($context, 'method_exists_null');
        $notNull = BasicBlockHelper::append($context, 'method_exists_not_null');
        $objectBlock = BasicBlockHelper::append($context, 'method_exists_obj');
        $notObject = BasicBlockHelper::append($context, 'method_exists_not_obj');
        $stringBlock = BasicBlockHelper::append($context, 'method_exists_str');
        $errBlock = BasicBlockHelper::append($context, 'method_exists_err');
        $mergeBlock = BasicBlockHelper::append($context, 'method_exists_merge');

        $context->builder->branchIf($isNull, $nullBlock, $notNull);

        $context->builder->positionAtEnd($nullBlock);
        self::emitTypeErrorAndAbort($context, \sprintf(self::OBJECT_OR_CLASS_TYPE_ERROR, 'null'));

        $context->builder->positionAtEnd($notNull);
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
        if (null !== $classLiteral) {
            // Runtime helper — autoloads like zend_lookup_class (#26407).
            $strResult = self::routeThroughPhpHelper($context, $objectOrClass, $methodArg);
        } else {
            $i1 = $context->getTypeFromString('int1');
            $strResult = $i1->constInt(0, false);
        }
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($errBlock);
        self::emitTypeErrorAndAbort($context, \sprintf(self::OBJECT_OR_CLASS_TYPE_ERROR, 'mixed'));

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($objResult->typeOf());
        $phi->addIncoming($objResult, $objectBlock);
        $phi->addIncoming($strResult, $stringBlock);

        return $phi;
    }

    private static function routeThroughPhpHelper(
        Context $context,
        JITVariable $objectOrClass,
        JITVariable $methodArg
    ): Value {
        $operandPtr = JitValueBox::valuePtrFromVariable($context, $objectOrClass);
        $methodStr = self::jitMethodNameArg($context, $methodArg);

        return StringMethodExists::invoke($context, $operandPtr, $methodStr);
    }

    private static function routeObjectThroughPhpHelper(
        Context $context,
        JITVariable $objectArg,
        JITVariable $methodArg
    ): Value {
        $obj = $context->helper->loadValue($objectArg);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $obj
        );
        $methodStr = self::jitMethodNameArg($context, $methodArg);

        return StringMethodExists::invoke($context, $ptr, $methodStr);
    }

    private static function jitMethodNameArg(Context $context, JITVariable $arg): Value
    {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'method_exists',
                1,
                'method'
            );
        }

        return JitStringBuiltinArg::lowerZparamStr(
            $context,
            $arg,
            'method_exists',
            1,
            'method'
        );
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

        return self::routeObjectThroughPhpHelper($context, $objectArg, $methodArg);
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
                $found = MagicMethodDispatch::hasInstanceMethod($object, $id, $method)
                    || VmReflection::isClosureInvokeMethod($className, $method);
                $classExists = $found
                    ? $i1->constInt(1, false)
                    : $i1->constInt(0, false);
            } else {
                $found = $object->hasMethod($id, $method)
                    || VmReflection::isClosureInvokeMethod($className, $method);
                $classExists = $found
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

    private static function emitTypeErrorAndAbort(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function scalarTypeError(int $type): string
    {
        switch ($type) {
            case JITVariable::TYPE_NATIVE_LONG:
                return \sprintf(self::OBJECT_OR_CLASS_TYPE_ERROR, 'int');
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return \sprintf(self::OBJECT_OR_CLASS_TYPE_ERROR, 'float');
            case JITVariable::TYPE_NATIVE_BOOL:
                return \sprintf(self::OBJECT_OR_CLASS_TYPE_ERROR, 'bool');
            case JITVariable::TYPE_NULL:
                return \sprintf(self::OBJECT_OR_CLASS_TYPE_ERROR, 'null');
            default:
                return \sprintf(self::OBJECT_OR_CLASS_TYPE_ERROR, 'mixed');
        }
    }
}
