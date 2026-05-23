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
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * intval() for integer or float arguments (truncates toward zero; subset of PHP).
 */
final class intval extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('intval() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_INTEGER === $v->type) {
            $frame->returnVar->int($v->toInt());

            return;
        }
        if (Variable::TYPE_FLOAT === $v->type) {
            $frame->returnVar->int((int) $v->toFloat());

            return;
        }
        if (Variable::TYPE_BOOLEAN === $v->type) {
            $frame->returnVar->int($v->toBool() ? 1 : 0);

            return;
        }
        if (Variable::TYPE_STRING === $v->type) {
            $frame->returnVar->int((int) $v->toString());

            return;
        }
        if (Variable::TYPE_NULL === $v->type) {
            $frame->returnVar->int(0);

            return;
        }
        throw new \LogicException('intval() only supports integers, floats, booleans, strings, and null in this compiler build');
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== count($args)) {
            throw new \LogicException('intval() requires exactly one argument');
        }
        $v = $context->helper->loadValue($args[0]);
        $i64 = $context->getTypeFromString('int64');
        switch ($args[0]->type) {
            case JITVariable::TYPE_NATIVE_LONG:
                return $v;
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return $context->builder->fpToSi($v, $i64);
            case JITVariable::TYPE_NATIVE_BOOL:
                return $context->builder->zExt($v, $i64);
            case JITVariable::TYPE_STRING:
                $ptr = $this->stringDataPtr($context, $v);
                $endPtr = $context->getTypeFromString('int8**')->constNull();
                $base = $context->getTypeFromString('int32')->constInt(10, false);
                $raw = $context->builder->call($context->lookupFunction('strtol'), $ptr, $endPtr, $base);

                return $context->builder->trunc($raw, $i64);
            case JITVariable::TYPE_NULL:
                return $i64->constInt(0, false);
            case JITVariable::TYPE_VALUE:
                return $this->valueToInt($context, $args[0]);
            default:
                throw new \LogicException('intval() only supports integers, floats, booleans, strings, and null in this compiler build');
        }
    }

    private function valueToInt(Context $context, JITVariable $arg): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);

        $nullBlock = BasicBlockHelper::append($context, 'intval_value_null');
        $longBlock = BasicBlockHelper::append($context, 'intval_value_long');
        $boolBlock = BasicBlockHelper::append($context, 'intval_value_bool');
        $doubleBlock = BasicBlockHelper::append($context, 'intval_value_double');
        $stringBlock = BasicBlockHelper::append($context, 'intval_value_string');
        $doneBlock = BasicBlockHelper::append($context, 'intval_value_done');

        $afterNull = BasicBlockHelper::append($context, 'intval_value_after_null');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NULL, false)),
            $nullBlock,
            $afterNull
        );
        $context->builder->positionAtEnd($nullBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterNull);
        $afterLong = BasicBlockHelper::append($context, 'intval_value_after_long');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NATIVE_LONG, false)),
            $longBlock,
            $afterLong
        );

        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $longEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterLong);
        $afterBool = BasicBlockHelper::append($context, 'intval_value_after_bool');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NATIVE_BOOL, false)),
            $boolBlock,
            $afterBool
        );

        $context->builder->positionAtEnd($boolBlock);
        $boolVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $boolInt = $context->builder->zExt($boolVal, $i64);
        $boolEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterBool);
        $afterDouble = BasicBlockHelper::append($context, 'intval_value_after_double');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NATIVE_DOUBLE, false)),
            $doubleBlock,
            $afterDouble
        );

        $context->builder->positionAtEnd($doubleBlock);
        $doubleVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $valuePtr);
        $doubleInt = $context->builder->fpToSi($doubleVal, $i64);
        $doubleEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterDouble);
        $fallbackBlock = BasicBlockHelper::append($context, 'intval_value_fallback');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_STRING, false)),
            $stringBlock,
            $fallbackBlock
        );

        $context->builder->positionAtEnd($stringBlock);
        $stringVal = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $ptr = $this->stringDataPtr($context, $stringVal);
        $endPtr = $context->getTypeFromString('int8**')->constNull();
        $base = $context->getTypeFromString('int32')->constInt(10, false);
        $raw = $context->builder->call($context->lookupFunction('strtol'), $ptr, $endPtr, $base);
        $stringInt = $context->builder->trunc($raw, $i64);
        $stringEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($fallbackBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i64, 'intval_value_phi');
        $phi->addIncoming($zero, $nullBlock);
        $phi->addIncoming($longVal, $longEndBlock);
        $phi->addIncoming($boolInt, $boolEndBlock);
        $phi->addIncoming($doubleInt, $doubleEndBlock);
        $phi->addIncoming($stringInt, $stringEndBlock);
        $phi->addIncoming($zero, $fallbackBlock);

        return $phi;
    }

    private function stringDataPtr(Context $context, Value $strPtr): Value
    {
        $structName = $strPtr->typeOf()->getElementType()->getName();
        $off = $context->structFieldMap[$structName]['value'];

        return $context->builder->structGep($strPtr, $off);
    }
}
