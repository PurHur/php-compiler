<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\ext\spl\ArrayIteratorBuiltin;
use PHPCompiler\ext\spl\ArrayObjectBuiltin;
use PHPCompiler\ext\spl\RecursiveArrayIteratorBuiltin;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringPropertyExists;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** JIT/AOT helper for property_exists() via PropertyExistsJitHelper PHP (#1372, #16442). */
final class JitPropertyExists
{
    private const TYPE_ERROR =
        'property_exists(): Argument #1 ($object_or_class) must be of type object|string, %s given';

    public static function invoke(Context $context, JITVariable $objectOrClass, JITVariable $propertyArg): Value
    {
        $propLiteral = JitStringArg::compileTimeLiteral($propertyArg);
        if (JITVariable::TYPE_OBJECT === $objectOrClass->type) {
            return self::forObject($context, $objectOrClass, $propertyArg, $propLiteral);
        }
        if (JITVariable::TYPE_VALUE === $objectOrClass->type) {
            return self::invokeFromValueBox($context, $objectOrClass, $propertyArg, $propLiteral);
        }
        if (JITVariable::TYPE_STRING !== $objectOrClass->type) {
            self::emitTypeErrorAndAbort($context, self::scalarTypeError($objectOrClass->type));
            $i1 = $context->getTypeFromString('int1');

            return $i1->constInt(0, false);
        }
        $classLiteral = JitStringArg::compileTimeLiteral($objectOrClass);
        if (null !== $classLiteral) {
            $propLiteral = JitStringArg::compileTimeLiteral($propertyArg);
            if (
                null !== $propLiteral
                && $context->type->object->hasUserDeclaredClass($classLiteral)
            ) {
                // Same-unit class: LLVM object table (static props included). NestedJIT
                // VmReflection misses AOT classes and returned false (#31966). Autoload for
                // not-yet-loaded names still uses the runtime helper (#26407).
                return ReflectionBuiltinHelper::propertyExistsLiteral(
                    $context,
                    $classLiteral,
                    $propLiteral
                );
            }
            // Runtime helper — autoloads like zend_lookup_class (#26407). Compile-time
            // fold would skip registered autoloaders for not-yet-loaded class strings.
            return self::routeThroughPhpHelper($context, $objectOrClass, $propertyArg);
        }

        throw new \LogicException('property_exists() requires a string literal class name in this compiler build');
    }

    private static function invokeFromValueBox(
        Context $context,
        JITVariable $objectOrClass,
        JITVariable $propertyArg,
        ?string $propLiteral
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

        $nullBlock = BasicBlockHelper::append($context, 'prop_exists_null');
        $notNull = BasicBlockHelper::append($context, 'prop_exists_not_null');
        $objectBlock = BasicBlockHelper::append($context, 'prop_exists_obj');
        $notObject = BasicBlockHelper::append($context, 'prop_exists_not_obj');
        $stringBlock = BasicBlockHelper::append($context, 'prop_exists_str');
        $errBlock = BasicBlockHelper::append($context, 'prop_exists_err');
        $mergeBlock = BasicBlockHelper::append($context, 'prop_exists_merge');

        $context->builder->branchIf($isNull, $nullBlock, $notNull);

        $context->builder->positionAtEnd($nullBlock);
        self::emitTypeErrorAndAbort($context, \sprintf(self::TYPE_ERROR, 'null'));

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
        $objResult = self::forObject($context, $objVar, $propertyArg, $propLiteral);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($notObject);
        $context->builder->branchIf($isString, $stringBlock, $errBlock);

        $context->builder->positionAtEnd($stringBlock);
        $classLiteral = JitStringArg::compileTimeLiteral($objectOrClass);
        if (null !== $classLiteral) {
            // Runtime helper — autoloads like zend_lookup_class (#26407).
            $strResult = self::routeThroughPhpHelper($context, $objectOrClass, $propertyArg);
        } else {
            $i1 = $context->getTypeFromString('int1');
            $strResult = $i1->constInt(0, false);
        }
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($errBlock);
        self::emitTypeErrorAndAbort($context, \sprintf(self::TYPE_ERROR, 'mixed'));

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($objResult->typeOf());
        $phi->addIncoming($objResult, $objectBlock);
        $phi->addIncoming($strResult, $stringBlock);

        return $phi;
    }

    private static function routeThroughPhpHelper(
        Context $context,
        JITVariable $objectOrClass,
        JITVariable $propertyArg
    ): Value {
        $operandPtr = JitValueBox::valuePtrFromVariable($context, $objectOrClass);
        $propertyStr = self::jitPropertyNameArg($context, $propertyArg);

        return StringPropertyExists::invoke($context, $operandPtr, $propertyStr);
    }

    private static function routeObjectThroughPhpHelper(
        Context $context,
        JITVariable $objectArg,
        JITVariable $propertyArg
    ): Value {
        $obj = $context->helper->loadValue($objectArg);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $obj
        );
        $propertyStr = self::jitPropertyNameArg($context, $propertyArg);

        return StringPropertyExists::invoke($context, $ptr, $propertyStr);
    }

    private static function jitPropertyNameArg(Context $context, JITVariable $arg): Value
    {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'property_exists',
                1,
                'property'
            );
        }

        return JitStringBuiltinArg::lowerZparamStr(
            $context,
            $arg,
            'property_exists',
            1,
            'property'
        );
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
                return \sprintf(self::TYPE_ERROR, 'int');
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return \sprintf(self::TYPE_ERROR, 'float');
            case JITVariable::TYPE_NATIVE_BOOL:
                return \sprintf(self::TYPE_ERROR, 'bool');
            case JITVariable::TYPE_NULL:
                return \sprintf(self::TYPE_ERROR, 'null');
            default:
                return \sprintf(self::TYPE_ERROR, 'mixed');
        }
    }

    private static function forObject(
        Context $context,
        JITVariable $objectArg,
        JITVariable $propertyArg,
        ?string $propLiteral
    ): Value {
        $objMap = $context->structFieldMap['__object__'];
        $obj = $context->helper->loadValue($objectArg);
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );
        // __PHP_Incomplete_Class — route through PHP helper so property_exists warns + false (#26366).
        $incompleteId = $context->type->object->classIdByName('__PHP_Incomplete_Class');
        if (null === $incompleteId) {
            $incompleteId = $context->type->object->classIdForLowerName('__php_incomplete_class');
        }
        if (null !== $incompleteId) {
            $isIncomplete = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $context->constantFromInteger($incompleteId, 'int64')
            );
            $incBlock = BasicBlockHelper::append($context, 'prop_exists_incomplete');
            $normBlock = BasicBlockHelper::append($context, 'prop_exists_complete');
            $mergeBlock = BasicBlockHelper::append($context, 'prop_exists_obj_merge');
            $context->builder->branchIf($isIncomplete, $incBlock, $normBlock);

            $context->builder->positionAtEnd($incBlock);
            $incResult = self::routeObjectThroughPhpHelper($context, $objectArg, $propertyArg);
            $context->builder->branch($mergeBlock);

            $context->builder->positionAtEnd($normBlock);
            $normResult = self::forCompleteObject($context, $objectArg, $propertyArg, $propLiteral, $classId);
            $context->builder->branch($mergeBlock);

            $context->builder->positionAtEnd($mergeBlock);
            $phi = $context->builder->phi($incResult->typeOf());
            $phi->addIncoming($incResult, $incBlock);
            $phi->addIncoming($normResult, $normBlock);

            return $phi;
        }

        return self::forCompleteObject($context, $objectArg, $propertyArg, $propLiteral, $classId);
    }

    private static function forCompleteObject(
        Context $context,
        JITVariable $objectArg,
        JITVariable $propertyArg,
        ?string $propLiteral,
        Value $classId
    ): Value {
        if (null !== $propLiteral) {
            // ARRAY_AS_PROPS flags are runtime — fold only via PHP helper (#31039).
            $isSplArray = self::isSplArrayStorageClassId($context, $classId);
            $splBlock = BasicBlockHelper::append($context, 'prop_exists_spl_array');
            $normBlock = BasicBlockHelper::append($context, 'prop_exists_not_spl_array');
            $mergeBlock = BasicBlockHelper::append($context, 'prop_exists_spl_merge');
            $context->builder->branchIf($isSplArray, $splBlock, $normBlock);

            $context->builder->positionAtEnd($splBlock);
            $splResult = self::routeObjectThroughPhpHelper($context, $objectArg, $propertyArg);
            $context->builder->branch($mergeBlock);

            $context->builder->positionAtEnd($normBlock);
            $normResult = self::forCompleteObjectLiteralProperty($context, $classId, $propLiteral);
            $context->builder->branch($mergeBlock);

            $context->builder->positionAtEnd($mergeBlock);
            $phi = $context->builder->phi($splResult->typeOf());
            $phi->addIncoming($splResult, $splBlock);
            $phi->addIncoming($normResult, $normBlock);

            return $phi;
        }

        return self::routeObjectThroughPhpHelper($context, $objectArg, $propertyArg);
    }

    private static function forCompleteObjectLiteralProperty(
        Context $context,
        Value $classId,
        string $propLiteral
    ): Value {
        // Enum pseudo-props name/value are case-sensitive (#23532).
        if ('name' === $propLiteral || 'value' === $propLiteral) {
            $enumExists = self::existsForEnumCasePropertyLiteral($context, $classId, $propLiteral);
            $regularExists = self::existsForClassIdLiteralProperty($context, $classId, $propLiteral);

            return $context->builder->or($enumExists, $regularExists);
        }

        return self::existsForClassIdLiteralProperty($context, $classId, $propLiteral);
    }

    private static function existsForEnumCasePropertyLiteral(
        Context $context,
        Value $classId,
        string $propLc
    ): Value {
        $i1 = $context->getTypeFromString('int1');
        $object = $context->type->object;
        $exists = $i1->constInt(0, false);
        foreach ($object->allClassNamesById() as $id => $className) {
            if (!$object->isEnumClassId($id)) {
                continue;
            }
            $isClass = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $context->constantFromInteger($id, 'int64')
            );
            $classExists = $i1->constInt(0, false);
            if ('name' === $propLc) {
                $classExists = $i1->constInt(1, false);
            } elseif ('value' === $propLc && $object->enumHasBacking($id)) {
                $classExists = $i1->constInt(1, false);
            }
            $exists = $context->builder->select($isClass, $classExists, $exists);
        }

        return $exists;
    }

    private static function isSplArrayStorageClassId(Context $context, Value $classId): Value
    {
        $i1 = $context->getTypeFromString('int1');
        $object = $context->type->object;
        $isSpl = $i1->constInt(0, false);
        foreach ([
            ArrayObjectBuiltin::CLASS_LC,
            ArrayIteratorBuiltin::CLASS_LC,
            RecursiveArrayIteratorBuiltin::CLASS_LC,
        ] as $classLc) {
            $id = $object->classIdByName($classLc);
            if (null === $id) {
                $id = $object->classIdForLowerName($classLc);
            }
            if (null === $id) {
                continue;
            }
            $match = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $context->constantFromInteger($id, 'int64')
            );
            $isSpl = $context->builder->or($isSpl, $match);
        }

        return $isSpl;
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
            $classExists = $object->propertyExistsFromScope($id, $property)
                ? $i1->constInt(1, false)
                : $i1->constInt(0, false);
            $exists = $context->builder->select($isClass, $classExists, $exists);
        }

        return $exists;
    }
}
