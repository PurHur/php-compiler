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
use PHPCompiler\VM\TypedPropertyCheck;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * floatval() for scalar arguments (subset of PHP standard library).
 */
final class floatval extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('floatval() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        TypedPropertyCheck::assertReadable($v);
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_INTEGER === $v->type) {
            $frame->returnVar->float((float) $v->toInt());

            return;
        }
        if (Variable::TYPE_FLOAT === $v->type) {
            $frame->returnVar->float($v->toFloat());

            return;
        }
        if (Variable::TYPE_BOOLEAN === $v->type) {
            $frame->returnVar->float($v->toBool() ? 1.0 : 0.0);

            return;
        }
        if (Variable::TYPE_STRING === $v->type) {
            $frame->returnVar->float((float) $v->toString());

            return;
        }
        if (Variable::TYPE_NULL === $v->type) {
            $frame->returnVar->float(0.0);

            return;
        }
        $enumFloat = VmScalarType::tryEnumCaseToFloat($frame, $v);
        if (null !== $enumFloat) {
            $frame->returnVar->float($enumFloat);

            return;
        }
        if (Variable::TYPE_ARRAY === $v->type || Variable::TYPE_OBJECT === $v->type) {
            $frame->returnVar->float(VmScalarType::zendFloatvalOperand($v, $frame));

            return;
        }
        throw new \LogicException('floatval() only supports integers, floats, booleans, strings, and null in this compiler build');
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== count($args)) {
            throw new \LogicException('floatval() requires exactly one argument');
        }
        $v = $context->helper->loadValue($args[0]);
        $double = $context->getTypeFromString('double');
        switch ($args[0]->type) {
            case JITVariable::TYPE_NATIVE_LONG:
                return $context->builder->siToFp($v, $double);
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return $v;
            case JITVariable::TYPE_NATIVE_BOOL:
                return $context->builder->uiToFp($v, $double);
            case JITVariable::TYPE_STRING:
                $ptr = $this->stringDataPtr($context, $this->jitString($context, $args[0], 'floatval() argument #1'));
                $endPtr = $context->getTypeFromString('int8**')->constNull();

                return $context->builder->call($context->lookupFunction('strtod'), $ptr, $endPtr);
            case JITVariable::TYPE_NULL:
                return $double->constReal(0.0);
            case JITVariable::TYPE_HASHTABLE:
                return JitScalarTypeCoerce::hashtableToDouble($context, $v);
            case JITVariable::TYPE_VALUE:
                return $this->valueToFloat($context, $args[0]);
            default:
                throw new \LogicException('floatval() only supports integers, floats, booleans, strings, and null in this compiler build');
        }
    }

    private function valueToFloat(Context $context, JITVariable $arg): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $double = $context->getTypeFromString('double');
        $zero = $double->constReal(0.0);

        $nullBlock = BasicBlockHelper::append($context, 'floatval_value_null');
        $longBlock = BasicBlockHelper::append($context, 'floatval_value_long');
        $boolBlock = BasicBlockHelper::append($context, 'floatval_value_bool');
        $doubleBlock = BasicBlockHelper::append($context, 'floatval_value_double');
        $stringBlock = BasicBlockHelper::append($context, 'floatval_value_string');
        $arrayBlock = BasicBlockHelper::append($context, 'floatval_value_array');
        $plainObjectBlock = BasicBlockHelper::append($context, 'floatval_value_plain_object');
        $doneBlock = BasicBlockHelper::append($context, 'floatval_value_done');

        $afterNull = BasicBlockHelper::append($context, 'floatval_value_after_null');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NULL, false)),
            $nullBlock,
            $afterNull
        );
        $context->builder->positionAtEnd($nullBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterNull);
        $afterLong = BasicBlockHelper::append($context, 'floatval_value_after_long');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NATIVE_LONG, false)),
            $longBlock,
            $afterLong
        );

        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $longFloat = $context->builder->siToFp($longVal, $double);
        $longEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterLong);
        $afterBool = BasicBlockHelper::append($context, 'floatval_value_after_bool');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NATIVE_BOOL, false)),
            $boolBlock,
            $afterBool
        );

        $context->builder->positionAtEnd($boolBlock);
        $boolVal = JitZendScalarCast::readBoolByteFromValueBox(
            $context,
            $valuePtr,
            $context->getTypeFromString('int8')
        );
        $boolFloat = $context->builder->uiToFp($boolVal, $double);
        $boolEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterBool);
        $afterDouble = BasicBlockHelper::append($context, 'floatval_value_after_double');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NATIVE_DOUBLE, false)),
            $doubleBlock,
            $afterDouble
        );

        $context->builder->positionAtEnd($doubleBlock);
        $doubleVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $valuePtr);
        $doubleEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterDouble);
        $objectEnumBlock = BasicBlockHelper::append($context, 'floatval_value_object_enum');
        $afterEnumDispatch = BasicBlockHelper::append($context, 'floatval_value_after_enum_dispatch');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_OBJECT, false)),
            $objectEnumBlock,
            $afterEnumDispatch
        );
        $enumDouble = null;
        $enumEndBlock = null;
        $context->builder->positionAtEnd($objectEnumBlock);
        $objPtr = $context->builder->call($context->lookupFunction('__value__readObject'), $valuePtr);
        $enumDouble = JitScalarEnumCoerce::tryEmitObjectEnumCaseLegacyCastToDouble(
            $context,
            $objPtr,
            'floatval',
            $afterEnumDispatch
        );
        if (null !== $enumDouble) {
            $enumEndBlock = $context->builder->getInsertBlock();
            $context->builder->branch($doneBlock);
        }
        $context->builder->positionAtEnd($afterEnumDispatch);
        $afterArray = BasicBlockHelper::append($context, 'floatval_value_after_array');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_HASHTABLE, false)),
            $arrayBlock,
            $afterArray
        );

        $context->builder->positionAtEnd($arrayBlock);
        $htPtr = $context->builder->call($context->lookupFunction('__value__readHashtable'), $valuePtr);
        $arrayFloat = JitScalarTypeCoerce::hashtableToDouble($context, $htPtr);
        $arrayEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterArray);
        $fallbackBlock = BasicBlockHelper::append($context, 'floatval_value_fallback');
        $unknownBlock = BasicBlockHelper::append($context, 'floatval_value_unknown');
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
        $plainObjectFloat = JitScalarTypeCoerce::emitPlainObjectToScalar($context, $plainObjPtr, 'float');
        $plainObjectEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($stringBlock);
        $stringVal = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $ptr = $this->stringDataPtr($context, $stringVal);
        $endPtr = $context->getTypeFromString('int8**')->constNull();
        $stringFloat = $context->builder->call($context->lookupFunction('strtod'), $ptr, $endPtr);
        $stringEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($unknownBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($double, 'floatval_value_phi');
        $phi->addIncoming($zero, $nullBlock);
        $phi->addIncoming($longFloat, $longEndBlock);
        $phi->addIncoming($boolFloat, $boolEndBlock);
        $phi->addIncoming($doubleVal, $doubleEndBlock);
        $phi->addIncoming($stringFloat, $stringEndBlock);
        $phi->addIncoming($arrayFloat, $arrayEndBlock);
        $phi->addIncoming($plainObjectFloat, $plainObjectEndBlock);
        if (null !== $enumDouble && null !== $enumEndBlock) {
            $phi->addIncoming($enumDouble, $enumEndBlock);
        }
        $phi->addIncoming($zero, $unknownBlock);

        return $phi;
    }
}
