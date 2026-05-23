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
use PHPCompiler\JIT\Variable as JITVariable;
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
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('boolval() requires exactly one argument');
        }
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
        if (1 !== count($args)) {
            throw new \LogicException('boolval() requires exactly one argument');
        }
        switch ($args[0]->type) {
            case JITVariable::TYPE_NATIVE_LONG:
                $v = $context->helper->loadValue($args[0]);
                $zero = $v->typeOf()->constInt(0, false);

                return $context->builder->icmp(Builder::INT_NE, $v, $zero);
            case JITVariable::TYPE_NATIVE_DOUBLE:
                $v = $context->helper->loadValue($args[0]);
                $zero = $v->typeOf()->constReal(0.0);

                return $context->builder->fcmp(Builder::REAL_ONE, $v, $zero);
            case JITVariable::TYPE_NATIVE_BOOL:
                return $context->helper->loadValue($args[0]);
            case JITVariable::TYPE_STRING:
                return self::stringTruthy($context, $context->helper->loadValue($args[0]));
            case JITVariable::TYPE_NULL:
                return $context->constantFromBool(false);
            case JITVariable::TYPE_VALUE:
                $loaded = $context->helper->loadValue($args[0]);
                $loaded = $context->builder->pointerCast(
                    $loaded,
                    $context->getTypeFromString('__value__*')
                );
                $map = $context->structFieldMap['__value__'];
                $typeByte = $context->builder->load(
                    $context->builder->structGep($loaded, $map['type'])
                );
                $i8 = $context->getTypeFromString('int8');
                $nullType = $i8->constInt(Variable::TYPE_NULL, false);
                $boolType = $i8->constInt(JITVariable::TYPE_NATIVE_BOOL, false);
                $isNull = $context->builder->icmp(Builder::INT_EQ, $typeByte, $nullType);
                $isBool = $context->builder->icmp(Builder::INT_EQ, $typeByte, $boolType);
                $boolByte = $context->builder->load(
                    $context->builder->gep(
                        $context->builder->structGep($loaded, $map['value']),
                        $i8->constInt(0, false)
                    )
                );
                $boolTruthy = $context->builder->icmp(Builder::INT_NE, $boolByte, $i8->constInt(0, false));
                $nonNull = $context->builder->icmp(Builder::INT_NE, $typeByte, $nullType);

                return $context->builder->select(
                    $isNull,
                    $context->constantFromBool(false),
                    $context->builder->select($isBool, $boolTruthy, $nonNull)
                );
            case JITVariable::TYPE_HASHTABLE:
                $ht = $context->helper->loadValue($args[0]);
                $num = $context->builder->call(
                    $context->lookupFunction('__hashtable__getNumElements'),
                    $ht
                );
                $zero = $num->typeOf()->constInt(0, false);

                return $context->builder->icmp(Builder::INT_NE, $num, $zero);
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
                return true;
            default:
                throw new \LogicException('boolval() does not support this value type in this compiler build');
        }
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
