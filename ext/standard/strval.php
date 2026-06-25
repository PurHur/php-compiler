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
use PHPCompiler\JIT\JitResourceIdString;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * strval() for scalar values supported by this compiler (subset of PHP).
 */
final class strval extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('strval() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        // Zend runs strval() side effects even when the return value is discarded (#5615).
        $vm = VM::running();
        if (null !== $vm) {
            $result = $vm->coerceVariableToString($v, $frame);
        } elseif (Variable::TYPE_NULL === $v->type) {
            $result = '';
        } else {
            $result = $v->toString(null, $frame);
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string($result);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== count($args)) {
            throw new \LogicException('strval() requires exactly one argument');
        }
        switch ($args[0]->type) {
            case JITVariable::TYPE_STRING:
                return $this->jitString($context, $args[0], 'strval() argument #1');
            case JITVariable::TYPE_NULL:
                return $context->builder->load($context->constantStringFromString(''));
            case JITVariable::TYPE_NATIVE_BOOL:
                return $this->boolToString($context, $context->helper->loadValue($args[0]));
            case JITVariable::TYPE_NATIVE_LONG:
                return JitResourceIdString::formatNativeLong(
                    $context,
                    $context->helper->loadValue($args[0])
                );
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return $this->formatToString($context, $context->helper->loadValue($args[0]), '%.14g');
            case JITVariable::TYPE_VALUE:
                return $this->valueToString($context, $args[0]->value);
            default:
                throw new \LogicException('strval() does not support this value type in this compiler build');
        }
    }

    public function valueToString(Context $context, Value $valuePtr): Value
    {
        $slot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__'));
        if ('__value__' === $context->getStringFromType($valuePtr->typeOf())) {
            $context->builder->store($valuePtr, $slot);
        } else {
            JitValueBox::copyFromPointer($context, $slot, $valuePtr);
        }
        $valuePtr = JitValueBox::pointer($context, $slot);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');

        $nullBlock = BasicBlockHelper::append($context, 'strval_value_null');
        $longBlock = BasicBlockHelper::append($context, 'strval_value_long');
        $boolBlock = BasicBlockHelper::append($context, 'strval_value_bool');
        $doubleBlock = BasicBlockHelper::append($context, 'strval_value_double');
        $stringBlock = BasicBlockHelper::append($context, 'strval_value_string');
        $doneBlock = BasicBlockHelper::append($context, 'strval_value_done');

        $afterNull = BasicBlockHelper::append($context, 'strval_value_after_null');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NULL, false)),
            $nullBlock,
            $afterNull
        );
        $context->builder->positionAtEnd($nullBlock);
        $empty = $context->builder->load($context->constantStringFromString(''));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterNull);
        $afterLong = BasicBlockHelper::append($context, 'strval_value_after_long');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NATIVE_LONG, false)),
            $longBlock,
            $afterLong
        );

        $context->builder->positionAtEnd($longBlock);
        $longStr = JitResourceIdString::formatNativeLong(
            $context,
            $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr)
        );
        $longEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterLong);
        $afterBool = BasicBlockHelper::append($context, 'strval_value_after_bool');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NATIVE_BOOL, false)),
            $boolBlock,
            $afterBool
        );

        $context->builder->positionAtEnd($boolBlock);
        $boolVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $boolStr = $this->boolToString($context, $context->builder->icmp(
            Builder::INT_NE,
            $boolVal,
            $boolVal->typeOf()->constInt(0, false)
        ));
        $boolEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterBool);
        $afterDouble = BasicBlockHelper::append($context, 'strval_value_after_double');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NATIVE_DOUBLE, false)),
            $doubleBlock,
            $afterDouble
        );

        $context->builder->positionAtEnd($doubleBlock);
        $doubleStr = $this->formatToString(
            $context,
            $context->builder->call($context->lookupFunction('__value__readDouble'), $valuePtr),
            '%.14g'
        );
        $doubleEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterDouble);
        $objectBlock = BasicBlockHelper::append($context, 'strval_value_object');
        $afterObject = BasicBlockHelper::append($context, 'strval_value_after_object');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_OBJECT, false)),
            $objectBlock,
            $afterObject
        );

        $context->builder->positionAtEnd($objectBlock);
        $objPtr = $context->builder->call($context->lookupFunction('__value__readObject'), $valuePtr);
        $context->type->object->emitEnumObjectStringErrorIfMatches($context, $objPtr);
        $objectEmpty = $context->builder->load($context->constantStringFromString(''));
        $objectEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterObject);
        $arrayBlock = BasicBlockHelper::append($context, 'strval_value_array');
        $afterArray = BasicBlockHelper::append($context, 'strval_value_after_array');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(Variable::TYPE_ARRAY, false)),
            $arrayBlock,
            $afterArray
        );

        $context->builder->positionAtEnd($arrayBlock);
        self::emitArrayToStringWarning($context);
        $arrayStr = $context->builder->load($context->constantStringFromString('Array'));
        $arrayEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterArray);
        $fallbackBlock = BasicBlockHelper::append($context, 'strval_value_fallback');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_STRING, false)),
            $stringBlock,
            $fallbackBlock
        );

        $context->builder->positionAtEnd($stringBlock);
        $stringVal = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($fallbackBlock);
        $fallbackEmpty = $context->builder->load($context->constantStringFromString(''));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($context->getTypeFromString('__string__*'));
        $phi->addIncoming($empty, $nullBlock);
        $phi->addIncoming($longStr, $longEndBlock);
        $phi->addIncoming($boolStr, $boolEndBlock);
        $phi->addIncoming($doubleStr, $doubleEndBlock);
        $phi->addIncoming($objectEmpty, $objectEndBlock);
        $phi->addIncoming($arrayStr, $arrayEndBlock);
        $phi->addIncoming($stringVal, $stringBlock);
        $phi->addIncoming($fallbackEmpty, $fallbackBlock);
        $restBlock = BasicBlockHelper::append($context, 'strval_value_rest');
        $context->builder->branch($restBlock);
        $context->builder->positionAtEnd($restBlock);

        return $phi;
    }

    /** Zend _convert_to_string() array branch (zend_operators.c, issue #5266). */
    private static function emitArrayToStringWarning(Context $context): void
    {
        $message = 'Array to string conversion';
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $msgLen = $sizeT->constInt(\strlen($message), false);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $msgLen,
            $i32->constInt(ErrorReporter::E_WARNING, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
    }

    private function boolToString(Context $context, Value $bool): Value
    {
        $trueBlock = BasicBlockHelper::append($context, 'strval_true');
        $falseBlock = BasicBlockHelper::append($context, 'strval_false');
        $endBlock = BasicBlockHelper::append($context, 'strval_bool_end');
        $context->builder->branchIf($bool, $trueBlock, $falseBlock);
        $context->builder->positionAtEnd($trueBlock);
        $trueStr = $context->builder->load($context->constantStringFromString('1'));
        $context->builder->branch($endBlock);
        $context->builder->positionAtEnd($falseBlock);
        $falseStr = $context->builder->load($context->constantStringFromString(''));
        $context->builder->branch($endBlock);
        $context->builder->positionAtEnd($endBlock);
        $phi = $context->builder->phi($trueStr->typeOf());
        $phi->addIncoming($trueStr, $trueBlock);
        $phi->addIncoming($falseStr, $falseBlock);

        return $phi;
    }

    private function formatToString(Context $context, Value $value, string $format): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        $charPtr = $context->getTypeFromString('char*');
        $i64 = $context->getTypeFromString('int64');
        $bufSize = $sizeT->constInt(64, false);
        $buf = $context->builder->call($context->lookupFunction('__mm__malloc'), $bufSize);
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        $fmt = $context->builder->pointerCast(
            $context->constantFromString($format),
            $charPtr
        );
        $written = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufChar,
            $bufSize,
            $fmt,
            $value
        );
        $len = $context->builder->zExt($written, $i64);
        $str = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $bufChar
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $buf);

        return $str;
    }
}
