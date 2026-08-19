<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\TypedPropertyCheck;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * boolval() for scalar values supported by this compiler (subset of PHP).
 */
final class boolval extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/type.c — ArgumentCountError (#23165).
        $this->requireExactArgCount($frame, 'boolval', 1);
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(self::isTruthy($v));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        // php-src ext/standard/type.c — ArgumentCountError (#23165).
        if (!$this->requireExactJitArgCount($context, $args, 'boolval', 1)) {
            return $context->constantFromBool(false);
        }
        switch ($args[0]->type) {
            case JITVariable::TYPE_NATIVE_LONG:
                $v = JitLongArg::lower($context, $args[0], 'boolval() argument #1');
                $zero = $v->typeOf()->constInt(0, false);

                return $context->builder->icmp(Builder::INT_NE, $v, $zero);
            case JITVariable::TYPE_NATIVE_DOUBLE:
                $v = $context->helper->loadValue($args[0]);
                $zero = $v->typeOf()->constReal(0.0);

                return $context->builder->fcmp(Builder::REAL_ONE, $v, $zero);
            case JITVariable::TYPE_NATIVE_BOOL:
                return $context->helper->loadValue($args[0]);
            case JITVariable::TYPE_STRING:
                return self::stringTruthy($context, $this->jitString($context, $args[0], 'boolval() argument #1'));
            case JITVariable::TYPE_NULL:
                return $context->constantFromBool(false);
            case JITVariable::TYPE_VALUE:
                $loaded = $context->helper->loadValue($args[0]);
                $loadedType = $context->getStringFromType($loaded->typeOf());
                if ('__value__' === $loadedType) {
                    $slot = \PHPCompiler\JIT\BasicBlockHelper::entryAlloca(
                        $context,
                        $loaded->typeOf()
                    );
                    $context->builder->store($loaded, $slot);
                    $loaded = \PHPCompiler\JIT\JitValueBox::pointer($context, $slot);
                } elseif ('__value__*' !== $loadedType) {
                    $loaded = $context->builder->pointerCast(
                        $loaded,
                        $context->getTypeFromString('__value__*')
                    );
                }

                return self::boxedTruthyScalar($context, $loaded);
            case JITVariable::TYPE_HASHTABLE:
                $ht = $context->helper->loadValue($args[0]);
                $num = $context->builder->call(
                    $context->lookupFunction('__hashtable__getNumElements'),
                    $ht
                );
                $zero = $num->typeOf()->constInt(0, false);

                return $context->builder->icmp(Builder::INT_NE, $num, $zero);
            case JITVariable::TYPE_OBJECT:
                // zend_is_true(IS_OBJECT) / zend_std_cast_object_to_type(_IS_BOOL) (#32463).
                return $context->constantFromBool(true);
            default:
                if ($args[0]->type & JITVariable::IS_NATIVE_ARRAY) {
                    $zero = $context->getTypeFromString('int64')->constInt(0, false);
                    $count = $context->constantFromInteger($args[0]->nextFreeElement, 'int64');

                    return $context->builder->icmp(Builder::INT_NE, $count, $zero);
                }
                throw new \LogicException('boolval() does not support this value type in this compiler build');
        }
    }

    public static function isTruthy(Variable $v): bool
    {
        $v = $v->resolveIndirect();
        TypedPropertyCheck::assertReadable($v);
        if ($v->isUndefined()) {
            return false;
        }
        switch ($v->type) {
            case Variable::TYPE_NULL:
                return false;
            case Variable::TYPE_INTEGER:
                return 0 !== $v->toInt();
            case Variable::TYPE_FLOAT:
                return 0.0 !== $v->toFloat();
            case Variable::TYPE_BOOLEAN:
                return $v->toBool();
            case Variable::TYPE_STRING:
                $s = $v->toString();

                return '' !== $s && '0' !== $s;
            case Variable::TYPE_ARRAY:
                return $v->toArray()->getNumElements() > 0;
            case Variable::TYPE_OBJECT:
                $object = $v->toObject();
                // SimpleXMLElement: empty($sxe) uses sxe_object_cast_ex, not object-always-true (#22714).
                if (\PHPCompiler\ext\simplexml\VmSimpleXml::handlesObjectCast($object)) {
                    return \PHPCompiler\ext\simplexml\VmSimpleXml::objectIsTruthy($object);
                }

                return true;
            case Variable::TYPE_ENUM_CASE:
                return true;
            default:
                throw new \LogicException('boolval() does not support this value type in this compiler build');
        }
    }

    /**
     * Boxed value truthiness for boolval()/castToBool (standalone AOT; #15704, #27410).
     *
     * Handles null/undefined/bool/int plus object/enum-case (zend_is_true). Object tags
     * in value boxes use the JIT IS_REFCOUNTED bit — compare kind with {@code & 0x7f}.
     */
    public static function isBoxedBoolTypeTag(Context $context, Value $typeByte): Value
    {
        $i8 = $context->getTypeFromString('int8');

        return $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(Variable::TYPE_BOOLEAN, false)),
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NATIVE_BOOL, false))
        );
    }

    public static function boxedTruthyScalar(Context $context, Value $valuePtr): Value
    {
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $false = $context->constantFromBool(false);
        $falsy = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(Variable::TYPE_NULL, false)),
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(Variable::TYPE_UNDEFINED, false))
        );

        $isBool = self::isBoxedBoolTypeTag($context, $typeByte);
        $valueField = $context->builder->structGep($valuePtr, $map['value']);
        $firstByte = $context->builder->inBoundsGEP(
            $valueField,
            $context->getTypeFromString('int32')->constInt(0, false),
            $context->getTypeFromString('int64')->constInt(0, false)
        );
        $boolTruthy = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->load($firstByte),
            $i8->constInt(0, false)
        );

        $isInt = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_INTEGER, false)
        );
        $zeroI64 = $context->getTypeFromString('int64')->constInt(0, false);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $intTruthy = $context->builder->icmp(Builder::INT_NE, $longVal, $zeroI64);

        // Objects (and enum cases) are truthy — zend_is_true / isTruthy() (#27410 AOT ternary).
        // Value boxes store JIT tags with IS_REFCOUNTED (TYPE_OBJECT=133); compare kind bits (#15704).
        $true = $context->constantFromBool(true);
        $kindMask = $i8->constInt(0x7f, false);
        $typeKind = $context->builder->and($typeByte, $kindMask);
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $typeKind,
            $i8->constInt(Variable::TYPE_OBJECT, false)
        );
        $isEnum = $context->builder->icmp(
            Builder::INT_EQ,
            $typeKind,
            $i8->constInt(Variable::TYPE_ENUM_CASE, false)
        );
        $objectTruthy = $context->builder->or($isObject, $isEnum);

        $typedTruthy = $context->builder->select(
            $isBool,
            $boolTruthy,
            $context->builder->select(
                $isInt,
                $intTruthy,
                $context->builder->select($objectTruthy, $true, $false)
            )
        );

        return $context->builder->select($falsy, $false, $typedTruthy);
    }

    public static function stringTruthy(Context $context, Value $strPtr): Value
    {
        $structName = $strPtr->typeOf()->getElementType()->getName();
        $map = $context->structFieldMap[$structName];
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $zero = $len->typeOf()->constInt(0, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $zero);
        $one = $len->typeOf()->constInt(1, false);
        $isOne = $context->builder->icmp(Builder::INT_EQ, $len, $one);
        $ch = $context->builder->load(
            $context->builder->structGep($strPtr, $map['value'])
        );
        $charZero = $ch->typeOf()->constInt(ord('0'), false);
        $isCharZero = $context->builder->icmp(Builder::INT_EQ, $ch, $charZero);
        $onlyZero = $context->builder->and($isOne, $isCharZero);
        $falsy = $context->builder->or($isEmpty, $onlyZero);

        return $context->builder->not($falsy);
    }
}
