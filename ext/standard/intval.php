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
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\TypedPropertyCheck;
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
        $this->requireArgCountRange($frame, 'intval', 1, 2);
        $argc = \count($frame->calledArgs);
        $base = 10;
        if (2 === $argc) {
            // Z_PARAM_LONG: caller strict_types → TypeError on null; else soft-null DEP+coerce (#31227).
            $base = VmMath::parseZParamLongBuiltinArgForFrame(
                $frame,
                1,
                'intval',
                2,
                'base'
            );
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        TypedPropertyCheck::assertReadable($v);
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING === $v->type) {
            $str = $v->toString();
            if ($base < 2 && 0 !== $base || $base > 36) {
                $frame->returnVar->int(0);

                return;
            }
            if (10 === $base) {
                $frame->returnVar->int((int) $str);

                return;
            }
            $frame->returnVar->int(VmMath::zendStrtol($str, $base));

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
        if (Variable::TYPE_ARRAY === $v->type || Variable::TYPE_OBJECT === $v->type) {
            $frame->returnVar->int(VmScalarType::zendIntvalOperand($v, $frame));

            return;
        }
        throw new \LogicException('intval() only supports integers, floats, booleans, strings, and null in this compiler build');
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        $argc = \count($args);
        if (!$this->requireArgCountRangeJit($context, $args, 'intval', 1, 2)) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        $i64 = $context->getTypeFromString('int64');
        if (2 === $argc) {
            // Soft-null outside strict_types; strict → TypeError (#31227).
            // Early return after compile-time null TypeError — open a dead insert block so the
            // call site can lower a discarded return without mid-block terminator (AOT verify;
            // peer dirname #31210 / settype #30506 / count #27446).
            if ($context->callerStrictTypes
                && (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false))) {
                JitIntdiv::lowerIntBuiltinArgForCaller($context, $args[1], 'intval', 2, 'base');
                BasicBlockHelper::ensureOpenInsertBlock($context, 'intval_null_base_te_cont');

                return $i64->constInt(0, false);
            }
            $baseVal = $this->parseBaseJit($context, $args[1]);
        } else {
            $baseVal = $i64->constInt(10, false);
        }
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
            case JITVariable::TYPE_HASHTABLE:
                return JitScalarTypeCoerce::hashtableToLong($context, $v);
            case JITVariable::TYPE_VALUE:
                return $this->valueToInt($context, $args[0], $baseVal);
            default:
                throw new \LogicException('intval() only supports integers, floats, booleans, strings, and null in this compiler build');
        }
    }

    private static function baseTypeErrorMessage(string $given): string
    {
        return 'intval(): Argument #2 ($base) must be of type int, '.$given.' given';
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
                // Z_PARAM_LONG: strict TypeError; weak soft-null DEP+0 (#31227).
                if ($context->callerStrictTypes) {
                    $this->emitBaseTypeErrorAndAbort($context, 'null');

                    return $i64->constInt(0, false);
                }
                if (!$context->isUserScriptAot()) {
                    JitIntdiv::emitNullIntDeprecation($context, 'intval', 2, 'base');
                }

                return $i64->constInt(0, false);
            case JITVariable::TYPE_STRING:
                return $this->stringToInt(
                    $context,
                    $this->jitString($context, $arg, 'intval() argument #2')
                );
            case JITVariable::TYPE_VALUE:
                return $this->parseBaseValueJit($context, $arg);
            case JITVariable::TYPE_OBJECT:
                // Prefer compile-time class label when constant (proper casing + smaller CFG) (#25724).
                $objectGiven = JitOperandTypeLabel::givenLabel($context, $arg);
                if ('object' === $objectGiven || 'mixed' === $objectGiven) {
                    JitStringBuiltinArg::emitObjectTypeErrorReject(
                        $context,
                        $arg,
                        'intval',
                        1,
                        'base',
                        'int'
                    );
                    BasicBlockHelper::ensureOpenInsertBlock($context, 'intval_base_obj_te_cont');
                } else {
                    $this->emitBaseTypeErrorAndAbort($context, $objectGiven);
                }

                return $i64->constInt(0, false);
            case JITVariable::TYPE_HASHTABLE:
                $this->emitBaseTypeErrorAndAbort($context, 'array');

                return $i64->constInt(0, false);
            default:
                $this->emitBaseTypeErrorAndAbort(
                    $context,
                    JitOperandTypeLabel::givenLabel($context, $arg)
                );

                return $i64->constInt(0, false);
        }
    }

    /**
     * Boxed base: coerce scalars like Zend Z_PARAM_LONG; TypeError on array/object (#25724).
     */
    private function parseBaseValueJit(Context $context, JITVariable $arg): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');

        $nullBlock = BasicBlockHelper::append($context, 'intval_base_null');
        $longBlock = BasicBlockHelper::append($context, 'intval_base_long');
        $boolBlock = BasicBlockHelper::append($context, 'intval_base_bool');
        $doubleBlock = BasicBlockHelper::append($context, 'intval_base_double');
        $stringBlock = BasicBlockHelper::append($context, 'intval_base_string');
        $objectBlock = BasicBlockHelper::append($context, 'intval_base_object');
        $arrayBlock = BasicBlockHelper::append($context, 'intval_base_array');
        $badBlock = BasicBlockHelper::append($context, 'intval_base_bad');
        $doneBlock = BasicBlockHelper::append($context, 'intval_base_done');

        $afterNull = BasicBlockHelper::append($context, 'intval_base_after_null');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NULL, false)),
            $nullBlock,
            $afterNull
        );
        $context->builder->positionAtEnd($nullBlock);
        // Boxed null: strict TypeError; weak soft-null DEP+0 (#31227).
        if ($context->callerStrictTypes) {
            $this->emitBaseTypeErrorAndAbort($context, 'null');
            $nullEnd = $context->builder->getInsertBlock();
            $context->builder->branch($doneBlock);
        } else {
            if (!$context->isUserScriptAot()) {
                JitIntdiv::emitNullIntDeprecation($context, 'intval', 2, 'base');
            }
            $nullEnd = $nullBlock;
            $context->builder->branch($doneBlock);
        }

        $context->builder->positionAtEnd($afterNull);
        $afterLong = BasicBlockHelper::append($context, 'intval_base_after_long');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NATIVE_LONG, false)),
            $longBlock,
            $afterLong
        );
        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $longEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterLong);
        $afterBool = BasicBlockHelper::append($context, 'intval_base_after_bool');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NATIVE_BOOL, false)),
            $boolBlock,
            $afterBool
        );
        $context->builder->positionAtEnd($boolBlock);
        $boolInt = JitZendScalarCast::readBoolByteFromValueBox($context, $valuePtr, $i64);
        $boolEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterBool);
        $afterDouble = BasicBlockHelper::append($context, 'intval_base_after_double');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NATIVE_DOUBLE, false)),
            $doubleBlock,
            $afterDouble
        );
        $context->builder->positionAtEnd($doubleBlock);
        $doubleVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $valuePtr);
        $doubleInt = $context->builder->fpToSi($doubleVal, $i64);
        $doubleEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterDouble);
        $afterString = BasicBlockHelper::append($context, 'intval_base_after_string');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_STRING, false)),
            $stringBlock,
            $afterString
        );
        $context->builder->positionAtEnd($stringBlock);
        $stringVal = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $stringInt = $this->stringToInt($context, $stringVal);
        $stringEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterString);
        $afterObject = BasicBlockHelper::append($context, 'intval_base_after_object');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_OBJECT, false)),
            $objectBlock,
            $afterObject
        );
        $context->builder->positionAtEnd($objectBlock);
        // Runtime class-id TypeError so AOT names stdClass/DateTime (#25724).
        $objPtr = $context->builder->call($context->lookupFunction('__value__readObject'), $valuePtr);
        $objVar = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $objPtr);
        JitStringBuiltinArg::emitObjectTypeErrorReject(
            $context,
            $objVar,
            'intval',
            1,
            'base',
            'int'
        );
        BasicBlockHelper::ensureOpenInsertBlock($context, 'intval_base_box_obj_te_cont');

        $context->builder->positionAtEnd($afterObject);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_HASHTABLE, false)),
            $arrayBlock,
            $badBlock
        );
        $context->builder->positionAtEnd($arrayBlock);
        $this->emitBaseTypeErrorAndAbort($context, 'array');

        $context->builder->positionAtEnd($badBlock);
        $this->emitBaseTypeErrorAndAbort(
            $context,
            JitOperandTypeLabel::givenLabel($context, $arg)
        );

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i64, 'intval_base_phi');
        $phi->addIncoming($i64->constInt(0, false), $nullEnd);
        $phi->addIncoming($longVal, $longEnd);
        $phi->addIncoming($boolInt, $boolEnd);
        $phi->addIncoming($doubleInt, $doubleEnd);
        $phi->addIncoming($stringInt, $stringEnd);

        return $phi;
    }

    private function emitBaseTypeErrorAndAbort(Context $context, string $given): void
    {
        ExceptionBridge::emitTypeErrorAndAbort($context, self::baseTypeErrorMessage($given));
        // Catchable throw terminates the block — keep insert open for callers (#22827 / #25724).
        BasicBlockHelper::ensureOpenInsertBlock($context, 'intval_base_te_cont');
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
        $arrayBlock = BasicBlockHelper::append($context, 'intval_value_array');
        $plainObjectBlock = BasicBlockHelper::append($context, 'intval_value_plain_object');
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
        $afterArray = BasicBlockHelper::append($context, 'intval_value_after_array');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_HASHTABLE, false)),
            $arrayBlock,
            $afterArray
        );

        $context->builder->positionAtEnd($arrayBlock);
        $htPtr = $context->builder->call($context->lookupFunction('__value__readHashtable'), $valuePtr);
        $arrayInt = JitScalarTypeCoerce::hashtableToLong($context, $htPtr);
        $arrayEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterArray);
        $fallbackBlock = BasicBlockHelper::append($context, 'intval_value_fallback');
        $unknownBlock = BasicBlockHelper::append($context, 'intval_value_unknown');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_STRING, false)),
            $stringBlock,
            $fallbackBlock
        );

        $context->builder->positionAtEnd($fallbackBlock);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_OBJECT, false)),
            $plainObjectBlock,
            $unknownBlock
        );

        $context->builder->positionAtEnd($plainObjectBlock);
        $plainObjPtr = $context->builder->call($context->lookupFunction('__value__readObject'), $valuePtr);
        $plainObjectInt = JitScalarTypeCoerce::emitPlainObjectToScalar($context, $plainObjPtr, 'int');
        $plainObjectEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($stringBlock);
        $stringVal = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $stringInt = $this->stringToIntWithBase($context, $stringVal, $baseVal);
        $stringEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($unknownBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i64, 'intval_value_phi');
        $phi->addIncoming($zero, $nullBlock);
        $phi->addIncoming($longVal, $longEndBlock);
        $phi->addIncoming($boolInt, $boolEndBlock);
        $phi->addIncoming($doubleInt, $doubleEndBlock);
        $phi->addIncoming($stringInt, $stringEndBlock);
        $phi->addIncoming($arrayInt, $arrayEndBlock);
        $phi->addIncoming($plainObjectInt, $plainObjectEndBlock);
        if (null !== $enumLong && null !== $enumEndBlock) {
            $phi->addIncoming($enumLong, $enumEndBlock);
        }
        $phi->addIncoming($zero, $unknownBlock);

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
        // strtol(3) via LibcExtern::ensureStrtolDecl after always-on drop (#31988).
        LibcExtern::ensureStrtolDecl($context);
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