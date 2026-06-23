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
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * intval() for scalar arguments (subset of PHP standard library).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(intval)
 */
final class intval extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('intval() requires between 1 and 2 arguments');
        }
        $base = 10;
        if (2 === $argc) {
            $base = self::parseBase($frame->calledArgs[1]->resolveIndirect());
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING === $v->type) {
            $str = $v->toString();
            if (0 === $base) {
                $base = VmMath::autodetectBase($str);
            }
            if ($base < 2 || $base > 36) {
                $frame->returnVar->int(0);

                return;
            }
            if (10 !== $base) {
                $result = VmMath::baseToZval($str, $base);
                $frame->returnVar->int((int) $result);

                return;
            }
            $frame->returnVar->int((int) $str);

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
        if (Variable::TYPE_NULL === $v->type) {
            $frame->returnVar->int(0);

            return;
        }
        $enumInt = VmScalarType::tryEnumCaseToInt($frame, $v);
        if (null !== $enumInt) {
            $frame->returnVar->int($enumInt);

            return;
        }
        throw new \LogicException('intval() only supports integers, floats, booleans, strings, and null in this compiler build');
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('intval() requires between 1 and 2 arguments');
        }
        $i64 = $context->getTypeFromString('int64');
        $baseVal = 2 === $argc
            ? $this->parseBaseJit($context, $args[1])
            : $i64->constInt(10, false);
        $v = $context->helper->loadValue($args[0]);
        switch ($args[0]->type) {
            case JITVariable::TYPE_NATIVE_LONG:
                return $v;
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return $context->builder->fpToSi($v, $i64);
            case JITVariable::TYPE_NATIVE_BOOL:
                return $context->builder->zExt($v, $i64);
            case JITVariable::TYPE_STRING:
                return $this->stringToIntWithBase(
                    $context,
                    $this->jitString($context, $args[0], 'intval() argument #1'),
                    $baseVal
                );
            case JITVariable::TYPE_NULL:
                return $i64->constInt(0, false);
            case JITVariable::TYPE_VALUE:
                return $this->valueToInt($context, $args[0], $baseVal);
            default:
                throw new \LogicException('intval() only supports integers, floats, booleans, strings, and null in this compiler build');
        }
    }

    private static function parseBase(Variable $v): int
    {
        if (Variable::TYPE_INTEGER === $v->type) {
            return $v->toInt();
        }
        if (Variable::TYPE_FLOAT === $v->type) {
            return (int) $v->toFloat();
        }
        if (Variable::TYPE_BOOLEAN === $v->type) {
            return $v->toBool() ? 1 : 0;
        }
        if (Variable::TYPE_NULL === $v->type) {
            return 0;
        }
        if (Variable::TYPE_STRING === $v->type) {
            $s = $v->toString();
            if ('' === $s || !is_numeric($s)) {
                throw new \TypeError('intval(): Argument #2 ($base) must be of type int, string given');
            }

            return (int) $s;
        }
        throw new \TypeError('intval(): Argument #2 ($base) must be of type int, '.self::zendTypeName($v->type).' given');
    }

    private static function zendTypeName(int $type): string
    {
        return match ($type) {
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            Variable::TYPE_RESOURCE => 'resource',
            default => 'unknown type',
        };
    }

    private function parseBaseJit(Context $context, JITVariable $arg): Value
    {
        $i64 = $context->getTypeFromString('int64');
        switch ($arg->type) {
            case JITVariable::TYPE_NATIVE_LONG:
                return $context->helper->loadValue($arg);
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return $context->builder->fpToSi($context->helper->loadValue($arg), $i64);
            case JITVariable::TYPE_NATIVE_BOOL:
                return $context->builder->zExt($context->helper->loadValue($arg), $i64);
            case JITVariable::TYPE_NULL:
                return $i64->constInt(0, false);
            case JITVariable::TYPE_STRING:
                return $this->stringToInt(
                    $context,
                    $this->jitString($context, $arg, 'intval() argument #2')
                );
            case JITVariable::TYPE_VALUE:
                return $this->valueToInt($context, $arg, $i64->constInt(10, false));
            default:
                throw new \LogicException('intval() argument #2 ($base) must be an integer in this compiler build');
        }
    }

    private function valueToInt(Context $context, JITVariable $arg, Value $baseVal): Value
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
        $boolVal = JitZendScalarCast::readBoolByteFromValueBox($context, $valuePtr, $i64);
        $boolInt = $boolVal;
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
        $objectEnumBlock = BasicBlockHelper::append($context, 'intval_value_object_enum');
        $afterEnumDispatch = BasicBlockHelper::append($context, 'intval_value_after_enum_dispatch');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_OBJECT, false)),
            $objectEnumBlock,
            $afterEnumDispatch
        );
        $enumLong = null;
        $enumEndBlock = null;
        $context->builder->positionAtEnd($objectEnumBlock);
        $objPtr = $context->builder->call($context->lookupFunction('__value__readObject'), $valuePtr);
        $enumLong = JitScalarEnumCoerce::tryEmitObjectEnumCaseLegacyCastToLong(
            $context,
            $objPtr,
            'intval',
            $afterEnumDispatch
        );
        if (null !== $enumLong) {
            $enumEndBlock = $context->builder->getInsertBlock();
            $context->builder->branch($doneBlock);
        }
        $context->builder->positionAtEnd($afterEnumDispatch);
        $fallbackBlock = BasicBlockHelper::append($context, 'intval_value_fallback');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_STRING, false)),
            $stringBlock,
            $fallbackBlock
        );

        $context->builder->positionAtEnd($stringBlock);
        $stringVal = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $stringInt = $this->stringToIntWithBase($context, $stringVal, $baseVal);
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
        if (null !== $enumLong && null !== $enumEndBlock) {
            $phi->addIncoming($enumLong, $enumEndBlock);
        }
        $phi->addIncoming($zero, $fallbackBlock);

        return $phi;
    }

    private function stringToInt(Context $context, Value $strPtr): Value
    {
        $i64 = $context->getTypeFromString('int64');

        return $this->stringToIntWithBase($context, $strPtr, $i64->constInt(10, false));
    }

    private function stringToIntWithBase(Context $context, Value $strPtr, Value $baseVal): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $base = $context->builder->trunc($baseVal, $i32);
        $zero = $i64->constInt(0, false);

        $validBb = BasicBlockHelper::append($context, 'intval_strtol_valid');
        $invalidBb = BasicBlockHelper::append($context, 'intval_strtol_invalid');
        $doneBb = BasicBlockHelper::append($context, 'intval_strtol_done');

        $isBaseZero = $context->builder->icmp(Builder::INT_EQ, $base, $i32->constInt(0, false));
        $badBase = $context->builder->or(
            $context->builder->icmp(Builder::INT_SLT, $base, $i32->constInt(2, false)),
            $context->builder->icmp(Builder::INT_SGT, $base, $i32->constInt(36, false))
        );
        $invalid = $context->builder->and(
            $context->builder->not($isBaseZero),
            $badBase
        );
        $context->builder->branchIf($invalid, $invalidBb, $validBb);

        $context->builder->positionAtEnd($invalidBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($validBb);
        $ptr = $this->stringDataPtr($context, $strPtr);
        $endPtr = $context->getTypeFromString('int8**')->constNull();
        $raw = $context->builder->call($context->lookupFunction('strtol'), $ptr, $endPtr, $base);
        $parsed = $context->builder->trunc($raw, $i64);
        $validEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($i64, 'intval_strtol_phi');
        $phi->addIncoming($zero, $invalidBb);
        $phi->addIncoming($parsed, $validEnd);

        return $phi;
    }
}
