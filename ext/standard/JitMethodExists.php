<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringCaseCompare;
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

        return self::forClassString($context, $objectOrClass, $methodArg, $methodLiteral);
    }

    /**
     * Class-string operand — same-unit names fold via the LLVM object table (#31966);
     * NestedJIT VmReflection misses AOT classes. Unknown literals autoload via the
     * PHP helper (#26407). Runtime names walk the table then autoload (#32701).
     */
    private static function forClassString(
        Context $context,
        JITVariable $objectOrClass,
        JITVariable $methodArg,
        ?string $methodLiteral
    ): Value {
        $classLiteral = JitStringArg::compileTimeLiteral($objectOrClass);
        if (null !== $classLiteral && null !== $methodLiteral
            && $context->type->object->hasUserDeclaredClass($classLiteral)) {
            return ReflectionBuiltinHelper::methodExistsLiteral(
                $context,
                $classLiteral,
                $methodLiteral
            );
        }
        if (null === $classLiteral && null !== $methodLiteral) {
            $classStr = $context->callerStrictTypes
                ? JitStringBuiltinArg::lowerStrictOrCoercible(
                    $context,
                    $objectOrClass,
                    'method_exists',
                    0,
                    'object_or_class'
                )
                : JitStringBuiltinArg::lowerZparamStr(
                    $context,
                    $objectOrClass,
                    'method_exists',
                    0,
                    'object_or_class'
                );

            return self::existsForRuntimeClassNameLiteralMethod(
                $context,
                $classStr,
                $objectOrClass,
                $methodArg,
                $methodLiteral
            );
        }

        return self::routeThroughPhpHelper($context, $objectOrClass, $methodArg);
    }

    private static function existsForRuntimeClassNameLiteralMethod(
        Context $context,
        Value $classStr,
        JITVariable $classArg,
        JITVariable $methodArg,
        string $method
    ): Value {
        StringCaseCompare::ensureStrcasecmpLinked($context);
        $i1 = $context->getTypeFromString('int1');
        $matched = $i1->constInt(0, false);
        $exists = $i1->constInt(0, false);
        $object = $context->type->object;
        $classData = self::stringDataPtr($context, $classStr);
        foreach ($object->allClassNamesById() as $id => $className) {
            $lit = $context->builder->load($context->constantStringFromString((string) $className));
            $cmp = $context->builder->call(
                $context->lookupFunction(StringCaseCompare::ABI_STRCASECMP),
                $classData,
                self::stringDataPtr($context, $lit)
            );
            $isMatch = $context->builder->icmp(
                Builder::INT_EQ,
                $cmp,
                $context->constantFromInteger(0, 'int32')
            );
            $matched = $context->builder->or($matched, $isMatch);
            if ($object->isEnumClassId($id)) {
                $classExists = self::enumMethodExistsConst($context, $id, $method);
            } else {
                $found = MagicMethodDispatch::hasInstanceMethod($object, $id, $method)
                    || VmReflection::isClosureInvokeMethod($className, $method);
                $classExists = $found
                    ? $i1->constInt(1, false)
                    : $i1->constInt(0, false);
            }
            $exists = $context->builder->select($isMatch, $classExists, $exists);
        }
        $knownBlock = BasicBlockHelper::append($context, 'method_exists_runtime_known');
        $autoloadBlock = BasicBlockHelper::append($context, 'method_exists_runtime_autoload');
        $mergeBlock = BasicBlockHelper::append($context, 'method_exists_runtime_merge');
        $context->builder->branchIf($matched, $knownBlock, $autoloadBlock);

        $context->builder->positionAtEnd($knownBlock);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($autoloadBlock);
        $helperResult = self::routeThroughPhpHelper($context, $classArg, $methodArg);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($exists, $knownBlock);
        $phi->addIncoming($helperResult, $autoloadBlock);

        return $phi;
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
        // Value-box tags may include IS_REFCOUNTED; compare the low 7 bits (#27108).
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_NULL, false)
        );
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JITVariable::TYPE_OBJECT & 0x7f, false)
        );
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JITVariable::TYPE_STRING & 0x7f, false)
        );

        $nullBlock = BasicBlockHelper::append($context, 'method_exists_null');
        $notNull = BasicBlockHelper::append($context, 'method_exists_not_null');
        $objectBlock = BasicBlockHelper::append($context, 'method_exists_obj');
        $notObject = BasicBlockHelper::append($context, 'method_exists_not_obj');
        $stringBlock = BasicBlockHelper::append($context, 'method_exists_str');
        $errBlock = BasicBlockHelper::append($context, 'method_exists_err');
        $mergeBlock = BasicBlockHelper::append($context, 'method_exists_merge');
        $i1 = $context->getTypeFromString('int1');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $i1);

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
        $context->builder->store($objResult, $resultSlot);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($notObject);
        $context->builder->branchIf($isString, $stringBlock, $errBlock);

        $context->builder->positionAtEnd($stringBlock);
        if (null !== $methodLiteral) {
            $classStr = $context->builder->call(
                $context->lookupFunction('__value__readString'),
                $valuePtr
            );
            $strResult = self::existsForRuntimeClassNameLiteralMethod(
                $context,
                $classStr,
                $objectOrClass,
                $methodArg,
                $methodLiteral
            );
        } else {
            $strResult = self::routeThroughPhpHelper($context, $objectOrClass, $methodArg);
        }
        $context->builder->store($strResult, $resultSlot);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($errBlock);
        self::emitTypeErrorAndAbort($context, \sprintf(self::OBJECT_OR_CLASS_TYPE_ERROR, 'mixed'));

        $context->builder->positionAtEnd($mergeBlock);

        return $context->builder->load($resultSlot);
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

    /** i8* payload of {@see __string__*} for {@see StringCaseCompare::ABI_STRCASECMP}. */
    private static function stringDataPtr(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->pointerCast(
            $context->builder->structGep($strPtr, $map['value']),
            $context->getTypeFromString('int8*')
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
        // Ensure living Dom\Attr::rename is visible for method_exists (#27108).
        if ('rename' === strtolower($method)) {
            \PHPCompiler\ext\dom\JitDomAttributeNodeNS::ensureLivingAttrMethods($context);
        }
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
