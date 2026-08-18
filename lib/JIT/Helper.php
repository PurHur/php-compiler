<?php

# This file is generated, changes you make will be lost.
# Make your changes in /compiler/lib/JIT/Helper.pre instead.

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\JIT;

require_once __DIR__.'/../OpCodeNames.php';

use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\OpCode;
use function PHPCompiler\opcode_type_name;
use PHPLLVM;
use PHPLLVM\Builder;

class Helper {
    
    public Context $context;

    public function __construct(Context $context) {
        $this->context = $context;
    }

    public function unaryOp(OpCode $opcode, Variable $var): Variable {
        $varValue = $this->loadValue($var);
        switch ($this->operandJitType($var)) {
            case Variable::TYPE_NATIVE_LONG:
                switch ($opcode->type) {
                    case OpCode::TYPE_UNARY_MINUS:
                        // Compile-time PHP_INT_MIN → double (#28761). Runtime overflow uses VmUnaryMinus.
                        if (Variable::KIND_VALUE === $var->kind
                            && null !== $var->value
                            && \PHPLLVM\Value::KIND_CONSTANT_INT === $var->value->getKind()
                        ) {
                            $const = (int) $this->context->llvm->lib->LLVMConstIntGetSExtValue($var->value->value);
                            if (\PHP_INT_MIN === $const) {
                                $result = $this->context->constantFromFloat(-(float) $const, 'double');
                                goto return_double;
                            }
                        }
                        $result = $this->context->builder->negate($varValue);
                        goto return_long;
                    case OpCode::TYPE_BITWISE_NOT:
                        $result = $this->context->builder->not($varValue);
                        goto return_long;
                }
                break;
            case Variable::TYPE_NATIVE_BOOL:
                switch ($opcode->type) {
                    case OpCode::TYPE_BITWISE_NOT:
                        $wide = $this->context->builder->zExt(
                            $varValue,
                            $this->context->getTypeFromString('int64')
                        );
                        $result = $this->context->builder->not($wide);
                        goto return_long;
                }
                break;
            case Variable::TYPE_VALUE:
                switch ($opcode->type) {
                    case OpCode::TYPE_UNARY_MINUS:
                        $constName = strtolower($var->compileTimeConstantName ?? '');
                        if ('inf' === $constName) {
                            $result = $this->context->getTypeFromString('double')->constReal(-INF);
                            goto return_double;
                        }
                        if ('nan' === $constName) {
                            $result = $this->context->getTypeFromString('double')->constReal(NAN);
                            goto return_double;
                        }
                        if (JitValueBox::isValueOperand($var)) {
                            $valuePtr = JitValueBox::valuePtrFromVariable($this->context, $var);
                            $map = $this->context->structFieldMap['__value__'];
                            $typeByte = $this->context->builder->load(
                                $this->context->builder->structGep($valuePtr, $map['type'])
                            );
                            $i8 = $this->context->getTypeFromString('int8');
                            $doubleBlock = BasicBlockHelper::append($this->context, 'unary_minus_vbox_double');
                            $longBlock = BasicBlockHelper::append($this->context, 'unary_minus_vbox_long');
                            $doneBlock = BasicBlockHelper::append($this->context, 'unary_minus_vbox_done');
                            $this->context->builder->branchIf(
                                $this->context->builder->icmp(
                                    Builder::INT_EQ,
                                    $typeByte,
                                    $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
                                ),
                                $doubleBlock,
                                $longBlock
                            );
                            $this->context->builder->positionAtEnd($doubleBlock);
                            $dval = $this->context->builder->call(
                                $this->context->lookupFunction('__value__readDouble'),
                                $valuePtr
                            );
                            $dneg = $this->context->builder->fNegate($dval);
                            $doubleEnd = $this->context->builder->getInsertBlock();
                            $this->context->builder->branch($doneBlock);
                            $this->context->builder->positionAtEnd($longBlock);
                            $lval = $this->context->builder->call(
                                $this->context->lookupFunction('__value__readLong'),
                                $valuePtr
                            );
                            // fneg(sitofp): sitofp(negate(INT_MIN)) wraps and keeps the wrong sign (#28761).
                            $f64 = $this->context->getTypeFromString('double');
                            $lnegFloat = $this->context->builder->fNegate(
                                $this->context->builder->sitofp($lval, $f64)
                            );
                            $longEnd = $this->context->builder->getInsertBlock();
                            $this->context->builder->branch($doneBlock);
                            $this->context->builder->positionAtEnd($doneBlock);
                            $dphi = $this->context->builder->phi($f64, 'unary_minus_vbox_double_phi');
                            $dphi->addIncoming($dneg, $doubleEnd);
                            $dphi->addIncoming($lnegFloat, $longEnd);
                            $result = $dphi;
                            goto return_double;
                        }
                        $long = JitLongArg::lower($this->context, $var, 'unary minus operand');
                        $result = $this->context->builder->negate($long);
                        goto return_long;
                    case OpCode::TYPE_BITWISE_NOT:
                        if (JitValueBox::isValueOperand($var)) {
                            $valuePtr = JitValueBox::valuePtrFromVariable($this->context, $var);
                            $map = $this->context->structFieldMap['__value__'];
                            $typeByte = $this->context->builder->load(
                                $this->context->builder->structGep($valuePtr, $map['type'])
                            );
                            $i8 = $this->context->getTypeFromString('int8');
                            $isDouble = $this->context->builder->icmp(
                                Builder::INT_EQ,
                                $typeByte,
                                $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
                            );
                            $doubleBlock = BasicBlockHelper::append($this->context, 'bitwise_not_vbox_double');
                            $longBlock = BasicBlockHelper::append($this->context, 'bitwise_not_vbox_long');
                            $doneBlock = BasicBlockHelper::append($this->context, 'bitwise_not_vbox_done');
                            $this->context->builder->branchIf($isDouble, $doubleBlock, $longBlock);
                            $this->context->builder->positionAtEnd($doubleBlock);
                            $doubleVal = $this->context->builder->call(
                                $this->context->lookupFunction('__value__readDouble'),
                                $valuePtr
                            );
                            $i64 = $this->context->getTypeFromString('int64');
                            $longFromDouble = $this->context->builder->fpToSi($doubleVal, $i64);
                            $notDouble = $this->context->builder->not($longFromDouble);
                            $doubleEnd = $this->context->builder->getInsertBlock();
                            $this->context->builder->branch($doneBlock);
                            $this->context->builder->positionAtEnd($longBlock);
                            $longVal = $this->context->builder->call(
                                $this->context->lookupFunction('__value__readLong'),
                                $valuePtr
                            );
                            $notLong = $this->context->builder->not($longVal);
                            $longEnd = $this->context->builder->getInsertBlock();
                            $this->context->builder->branch($doneBlock);
                            $this->context->builder->positionAtEnd($doneBlock);
                            $resultPhi = $this->context->builder->phi($i64, 'bitwise_not_vbox_long_phi');
                            $resultPhi->addIncoming($notDouble, $doubleEnd);
                            $resultPhi->addIncoming($notLong, $longEnd);
                            $result = $resultPhi;
                            goto return_long;
                        }
                        $long = JitLongArg::lower($this->context, $var, 'bitwise not operand');
                        $result = $this->context->builder->not($long);
                        goto return_long;
                }
                break;
            case Variable::TYPE_NATIVE_DOUBLE:
                switch ($opcode->type) {
                    case OpCode::TYPE_UNARY_MINUS:
                        $result = $this->context->builder->fNegate($varValue);
                        goto return_double;
                    case OpCode::TYPE_BITWISE_NOT:
                        $i64 = $this->context->getTypeFromString('int64');
                        $long = $this->context->builder->fpToSi($varValue, $i64);
                        $result = $this->context->builder->not($long);
                        goto return_long;
                }
                break;
            case Variable::TYPE_STRING:
                if (OpCode::TYPE_BITWISE_NOT === $opcode->type) {
                    $result = $this->context->builder->call(
                        $this->context->lookupFunction('__string__bitwiseNot'),
                        $varValue
                    );

                    goto return_string;
                }
                break;
        }
        $type = opcode_type_name($opcode->type);
        throw new \LogicException("Reached end of switch, can't handle unary operation yet: $type for type {$var->type}");
return_double:
        return new Variable($this->context, Variable::TYPE_NATIVE_DOUBLE, Variable::KIND_VALUE, $result);
return_long:
        return $this->nativeLongResultVariable($result);
return_bool:
        return new Variable($this->context, Variable::TYPE_NATIVE_BOOL, Variable::KIND_VALUE, $result);
return_string:
        return new Variable($this->context, Variable::TYPE_STRING, Variable::KIND_VALUE, $result);
    }

    public function binaryOp(OpCode $opcode, Variable $left, Variable $right): Variable {
        // Operand eval (fromLiteral / value_copy) can leave insert cleared after const-string
        // builder swaps; reload must not create parentless loads (#26756).
        // Prefer void-ret rewrite so compare after value-box assign is not orphaned (#31101).
        BasicBlockHelper::ensureOpenInsertBlockReplacingVoidReturn($this->context, 'binary_op_load_cont');
        JitEnumNumericOperandGuard::guardArithmetic($this->context, $opcode->type, $left, $right);
        if (OpCode::TYPE_SHIFT_LEFT === $opcode->type || OpCode::TYPE_SHIFT_RIGHT === $opcode->type) {
            JitShiftOperandGuard::guardOperands($this->context, $opcode->type, $left, $right);
        }
        if (OpCode::TYPE_BITWISE_AND === $opcode->type
            || OpCode::TYPE_BITWISE_OR === $opcode->type
            || OpCode::TYPE_BITWISE_XOR === $opcode->type
            || OpCode::TYPE_SHIFT_LEFT === $opcode->type
            || OpCode::TYPE_SHIFT_RIGHT === $opcode->type
        ) {
            $folded = $this->tryFoldCoreIntBitwise($opcode->type, $left, $right);
            if (null !== $folded) {
                $result = $this->context->getTypeFromString('int64')->constInt($folded, false);

                goto return_long;
            }
        }
        $leftValue = $this->loadValue($left);
        $rightValue = $this->loadValue($right);
        $leftType = $this->operandJitType($left);
        $rightType = $this->operandJitType($right);
        if (OpCode::TYPE_SHIFT_LEFT === $opcode->type || OpCode::TYPE_SHIFT_RIGHT === $opcode->type) {
            if (Variable::TYPE_NATIVE_DOUBLE === $leftType || Variable::TYPE_NATIVE_DOUBLE === $rightType) {
                $result = $this->emitShiftWithFloatOperands($opcode, $leftValue, $rightValue, $leftType, $rightType);
                goto return_long;
            }
            if (Variable::TYPE_NATIVE_BOOL === $leftType || Variable::TYPE_NATIVE_BOOL === $rightType) {
                $result = $this->emitShiftWithBoolOperands($opcode, $leftValue, $rightValue, $leftType, $rightType);
                goto return_long;
            }
        }
        if (OpCode::TYPE_LOGICAL_XOR === $opcode->type) {
            $zeroI64 = $this->context->getTypeFromString('int64')->constInt(0, false);
            if (Variable::TYPE_NATIVE_BOOL === $leftType) {
                $leftTruth = $leftValue;
            } elseif (Variable::TYPE_STRING === $leftType) {
                $leftLen = $this->context->builder->call(
                    $this->context->lookupFunction('__string__strlen'),
                    $leftValue
                );
                $leftTruth = $this->context->builder->icmp(\PHPLLVM\Builder::INT_NE, $leftLen, $zeroI64);
            } else {
                $leftTruth = $this->context->builder->icmp(\PHPLLVM\Builder::INT_NE, $leftValue, $zeroI64);
            }
            if (Variable::TYPE_NATIVE_BOOL === $rightType) {
                $rightTruth = $rightValue;
            } elseif (Variable::TYPE_STRING === $rightType) {
                $rightLen = $this->context->builder->call(
                    $this->context->lookupFunction('__string__strlen'),
                    $rightValue
                );
                $rightTruth = $this->context->builder->icmp(\PHPLLVM\Builder::INT_NE, $rightLen, $zeroI64);
            } else {
                $rightTruth = $this->context->builder->icmp(\PHPLLVM\Builder::INT_NE, $rightValue, $zeroI64);
            }
            $result = $this->context->builder->xor($leftTruth, $rightTruth);
            goto return_bool;
        }
        if (
            OpCode::TYPE_EQUAL === $opcode->type
            || OpCode::TYPE_IDENTICAL === $opcode->type
            || OpCode::TYPE_NOT_EQUAL === $opcode->type
            || OpCode::TYPE_NOT_IDENTICAL === $opcode->type
        ) {
            $negate = OpCode::TYPE_NOT_EQUAL === $opcode->type || OpCode::TYPE_NOT_IDENTICAL === $opcode->type;
            // Only native TYPE_STRING literals — VALUE boxes can carry compileTimeString
            // from boxed string literals; loadValue then yields `__value__` / `__value__*`
            // and VmStringCompare::identical structGep crashes (#24429 sockets SIGSEGV).
            if (
                null !== $right->compileTimeString
                && Variable::TYPE_STRING === $right->type
                && JitValueBox::isValueOperand($left)
            ) {
                $result = JitStringCompare::identicalStringToValue(
                    $this->context,
                    $rightValue,
                    $left
                );
                if ($negate) {
                    $result = $this->context->builder->xor(
                        $result,
                        $this->context->getTypeFromString('int1')->constInt(1, false)
                    );
                }
                goto return_bool;
            }
            if (
                null !== $left->compileTimeString
                && Variable::TYPE_STRING === $left->type
                && JitValueBox::isValueOperand($right)
            ) {
                $result = JitStringCompare::identicalStringToValue(
                    $this->context,
                    $leftValue,
                    $right
                );
                if ($negate) {
                    $result = $this->context->builder->xor(
                        $result,
                        $this->context->getTypeFromString('int1')->constInt(1, false)
                    );
                }
                goto return_bool;
            }
        }
restart:
        switch (type_pair($leftType, $rightType)) {
            case TYPE_PAIR_NATIVE_LONG_NATIVE_DOUBLE:
                $leftType = Variable::TYPE_NATIVE_DOUBLE;
                $leftValue = $this->context->builder->siToFp($leftValue, $rightValue->typeOf());
                goto restart;
            case TYPE_PAIR_NATIVE_DOUBLE_NATIVE_LONG:
                $rightType = Variable::TYPE_NATIVE_DOUBLE;
                $rightValue = $this->context->builder->siToFp($rightValue, $leftValue->typeOf());
                goto restart;
            case TYPE_PAIR_NATIVE_DOUBLE_NATIVE_DOUBLE:
                switch ($opcode->type) {
                    case OpCode::TYPE_MUL:
                        $result = $this->context->builder->fmul($leftValue, $rightValue);
                        goto return_double;
                    case OpCode::TYPE_PLUS:
                        $result = $this->context->builder->fadd($leftValue, $rightValue);
                        goto return_double;
                    case OpCode::TYPE_MINUS:
                        $result = $this->context->builder->fsub($leftValue, $rightValue);
                        goto return_double;
                    case OpCode::TYPE_DIV:
                        JitNumericDivisionGuard::emitZeroDoubleDivisorGuard(
                            $this->context,
                            $rightValue,
                            'Division by zero'
                        );
                        $result = $this->context->builder->fdiv($leftValue, $rightValue);
                        goto return_double;
                    case OpCode::TYPE_MODULO:
                        JitNumericDivisionGuard::emitZeroDoubleDivisorGuard(
                            $this->context,
                            $rightValue,
                            'Modulo by zero'
                        );
                        $i64 = $this->context->getTypeFromString('int64');
                        $leftLong = $this->context->builder->fpToSi($leftValue, $i64);
                        $rightLong = $this->context->builder->fpToSi($rightValue, $i64);
                        $result = JitNumericDivisionGuard::signedModulo($this->context, $leftLong, $rightLong);
                        goto return_long;
                    case OpCode::TYPE_BITWISE_AND:
                    case OpCode::TYPE_BITWISE_OR:
                    case OpCode::TYPE_BITWISE_XOR:
                    case OpCode::TYPE_SHIFT_LEFT:
                    case OpCode::TYPE_SHIFT_RIGHT:
                        break;
                    case OpCode::TYPE_GREATER_OR_EQUAL:
                    case OpCode::TYPE_SMALLER_OR_EQUAL:
                    case OpCode::TYPE_GREATER:
                    case OpCode::TYPE_SMALLER:
                    case OpCode::TYPE_IDENTICAL:
                    case OpCode::TYPE_EQUAL:
                    case OpCode::TYPE_NOT_IDENTICAL:
                    case OpCode::TYPE_NOT_EQUAL:
                        $result = JitFloatCompare::relationalCompare(
                            $this->context,
                            $opcode->type,
                            $leftValue,
                            $rightValue
                        );
                        goto return_bool;
                    case OpCode::TYPE_SPACESHIP:
                        $result = JitFloatCompare::spaceship($this->context, $leftValue, $rightValue);
                        goto return_long;
                }
                break;
            case TYPE_PAIR_NATIVE_LONG_NATIVE_LONG:
                switch ($opcode->type) {
                    case OpCode::TYPE_MUL:
                        $folded = JitLongArithOverflow::tryFoldBinary($this->context, $opcode->type, $left, $right);
                        if (null !== $folded) {
                            return $folded;
                        }
                        $__right = $this->context->builder->intCast($rightValue, $leftValue->typeOf());

                        return JitLongArithOverflow::binaryNativeLong(
                            $this->context,
                            $opcode->type,
                            $leftValue,
                            $__right
                        );
                    case OpCode::TYPE_PLUS:
                        $folded = JitLongArithOverflow::tryFoldBinary($this->context, $opcode->type, $left, $right);
                        if (null !== $folded) {
                            return $folded;
                        }
                        $__right = $this->context->builder->intCast($rightValue, $leftValue->typeOf());

                        return JitLongArithOverflow::binaryNativeLong(
                            $this->context,
                            $opcode->type,
                            $leftValue,
                            $__right
                        );
                    case OpCode::TYPE_MINUS:
                        $__right = $this->context->builder->intCast($rightValue, $leftValue->typeOf());
                            
                            
                        

                        

                        

                        

                        

                        
                            $result = $this->context->builder->subNoSignedWrap($leftValue, $__right);
    
                        goto return_long;
                    case OpCode::TYPE_DIV:
                        // PHP `/` is always float (zend_div). Integer sdiv made `7/2` int(3) (#31968).
                        $__right = $this->context->builder->intCast($rightValue, $leftValue->typeOf());
                        $f64 = $this->context->getTypeFromString('double');
                        $leftDouble = $this->context->builder->siToFp($leftValue, $f64);
                        $rightDouble = $this->context->builder->siToFp($__right, $f64);
                        JitNumericDivisionGuard::emitZeroDoubleDivisorGuard(
                            $this->context,
                            $rightDouble,
                            'Division by zero'
                        );
                        $result = $this->context->builder->fdiv($leftDouble, $rightDouble);
                        goto return_double;
                    case OpCode::TYPE_MODULO:
                        $result = JitNumericDivisionGuard::signedModulo($this->context, $leftValue, $rightValue);
                        goto return_long;
                    case OpCode::TYPE_BITWISE_AND:
                    case OpCode::TYPE_BITWISE_OR:
                    case OpCode::TYPE_BITWISE_XOR:
                        $folded = $this->tryFoldCoreIntBitwise($opcode->type, $left, $right);
                        if (null !== $folded) {
                            $result = $this->context->getTypeFromString('int64')->constInt($folded, false);
                            goto return_long;
                        }
                        $__right = $this->context->builder->intCast($rightValue, $leftValue->typeOf());
                        if (OpCode::TYPE_BITWISE_AND === $opcode->type) {
                            $result = $this->context->builder->bitwiseAnd($leftValue, $__right);
                        } elseif (OpCode::TYPE_BITWISE_OR === $opcode->type) {
                            $result = $this->context->builder->bitwiseOr($leftValue, $__right);
                        } else {
                            $result = $this->context->builder->bitwiseXor($leftValue, $__right);
                        }

                        goto return_long;
                    case OpCode::TYPE_SHIFT_LEFT:
                    case OpCode::TYPE_SHIFT_RIGHT:
                        $__right = $this->context->builder->intCast($rightValue, $leftValue->typeOf());
                        $result = $this->emitGuardedIntShift($opcode->type, $leftValue, $__right);
                        goto return_long;
                    case OpCode::TYPE_GREATER_OR_EQUAL:
                        $__right = $this->context->builder->intCast($rightValue, $leftValue->typeOf());
                            
                            
                        

                        

                        

                        

                        

                        

                        

                        

                        

                        

                        

                        

                        
                            $cmp = \PHPLLVM\Builder::INT_SGE;
                            
                            $result = $this->context->builder->icmp($cmp, $leftValue, $__right);
    
                        goto return_bool;
                    case OpCode::TYPE_SMALLER_OR_EQUAL:
                        $__right = $this->context->builder->intCast($rightValue, $leftValue->typeOf());
                            
                            
                        

                        

                        

                        

                        

                        

                        

                        

                        

                        

                        
                            $cmp = \PHPLLVM\Builder::INT_SLE;
                            
                            $result = $this->context->builder->icmp($cmp, $leftValue, $__right);
    
                        goto return_bool;
                    case OpCode::TYPE_GREATER:
                        $__right = $this->context->builder->intCast($rightValue, $leftValue->typeOf());
                            
                            
                        

                        

                        

                        

                        

                        

                        

                        

                        

                        

                        

                        
                            $cmp = \PHPLLVM\Builder::INT_SGT;
                            
                            $result = $this->context->builder->icmp($cmp, $leftValue, $__right);
    
                        goto return_bool;
                    case OpCode::TYPE_SMALLER:
                        $__right = $this->context->builder->intCast($rightValue, $leftValue->typeOf());
                            
                            
                        

                        

                        

                        

                        

                        

                        

                        

                        

                        
                            $cmp = \PHPLLVM\Builder::INT_SLT;
                            
                            $result = $this->context->builder->icmp($cmp, $leftValue, $__right);
    
                        goto return_bool;
                    case OpCode::TYPE_IDENTICAL:
                    case OpCode::TYPE_EQUAL:
                        $__right = $this->context->builder->intCast($rightValue, $leftValue->typeOf());
                            
                            
                        

                        

                        

                        

                        

                        

                        

                        

                        

                        

                        

                        

                        

                        $result = JitValueCompare::nativeLongEqualWithResourceIdentity(
                            $this->context,
                            $leftValue,
                            $__right
                        );
    
                        goto return_bool;
                    case OpCode::TYPE_NOT_IDENTICAL:
                    case OpCode::TYPE_NOT_EQUAL:
                        $__right = $this->context->builder->intCast($rightValue, $leftValue->typeOf());
                            
                            
                        

                        

                        

                        

                        

                        

                        

                        

                        

                        

                        

                        

                        

                        

                        

                        $same = JitValueCompare::nativeLongEqualWithResourceIdentity(
                            $this->context,
                            $leftValue,
                            $__right
                        );
                        $result = $this->context->builder->icmp(
                            \PHPLLVM\Builder::INT_EQ,
                            $same,
                            $this->context->getTypeFromString('int1')->constInt(0, false)
                        );
    
                        goto return_bool;
                    case OpCode::TYPE_NOT_IDENTICAL:
                        $__right = $this->context->builder->intCast($rightValue, $leftValue->typeOf());
                        $result = $this->context->builder->icmp(\PHPLLVM\Builder::INT_NE, $leftValue, $__right);
                        goto return_bool;
                    case OpCode::TYPE_SPACESHIP:
                        $__right = $this->context->builder->intCast($rightValue, $leftValue->typeOf());
                        $lt = $this->context->builder->icmp(\PHPLLVM\Builder::INT_SLT, $leftValue, $__right);
                        $gt = $this->context->builder->icmp(\PHPLLVM\Builder::INT_SGT, $leftValue, $__right);
                        $ty = $leftValue->typeOf();
                        $negOne = $ty->constInt(-1, true);
                        $one = $ty->constInt(1, true);
                        $zero = $ty->constInt(0, false);
                        $result = $this->context->builder->select($gt, $one, $this->context->builder->select($lt, $negOne, $zero));
                        goto return_long;
                }
                break;
            case TYPE_PAIR_NATIVE_LONG_NATIVE_BOOL:
    
            if (OpCode::TYPE_EQUAL === $opcode->type || OpCode::TYPE_NOT_EQUAL === $opcode->type) {
                if (Variable::TYPE_NATIVE_LONG === $rightType) {
                    $leftLong = JitLongArg::lower($this->context, $left, 'binary op left operand');
                    $__right = $this->context->builder->intCast($rightValue, $leftLong->typeOf());
                    $cmp = OpCode::TYPE_EQUAL === $opcode->type ? Builder::INT_EQ : Builder::INT_NE;
                    $result = $this->context->builder->icmp($cmp, $leftLong, $__right);
                    goto return_bool;
                }
            }
            if (OpCode::TYPE_IDENTICAL === $opcode->type) {
                    $result = $this->context->getTypeFromString('int1')->constInt(0, false);
                    goto return_bool;
                }
                if (OpCode::TYPE_NOT_IDENTICAL === $opcode->type) {
                    $result = $this->context->getTypeFromString('int1')->constInt(1, false);
                    goto return_bool;
                }
                if (OpCode::TYPE_NOT_IDENTICAL === $opcode->type) {
                    $result = $leftValue->typeOf()->constInt(1, false);
                    goto return_bool;
                }
                if (OpCode::TYPE_EQUAL === $opcode->type) {
                    $__right = $this->context->builder->zExt($rightValue, $leftValue->typeOf());
                    $result = $this->context->builder->icmp(\PHPLLVM\Builder::INT_EQ, $leftValue, $__right);
                    goto return_bool;
                }
                if (OpCode::TYPE_NOT_EQUAL === $opcode->type) {
                    $__right = $this->context->builder->zExt($rightValue, $leftValue->typeOf());
                    $result = $this->context->builder->icmp(\PHPLLVM\Builder::INT_NE, $leftValue, $__right);
                    goto return_bool;
                }
                break;
            case TYPE_PAIR_NATIVE_BOOL_NATIVE_LONG:
                if (OpCode::TYPE_IDENTICAL === $opcode->type) {
                    $result = $this->context->getTypeFromString('int1')->constInt(0, false);
                    goto return_bool;
                }
                if (OpCode::TYPE_NOT_IDENTICAL === $opcode->type) {
                    $result = $this->context->getTypeFromString('int1')->constInt(1, false);
                    goto return_bool;
                }
                if (OpCode::TYPE_NOT_IDENTICAL === $opcode->type) {
                    $result = $leftValue->typeOf()->constInt(1, false);
                    goto return_bool;
                }
                if (OpCode::TYPE_EQUAL === $opcode->type) {
                    $__left = $this->context->builder->zExt($leftValue, $rightValue->typeOf());
                    $result = $this->context->builder->icmp(\PHPLLVM\Builder::INT_EQ, $__left, $rightValue);
                    goto return_bool;
                }
                if (OpCode::TYPE_NOT_EQUAL === $opcode->type) {
                    $__left = $this->context->builder->zExt($leftValue, $rightValue->typeOf());
                    $result = $this->context->builder->icmp(\PHPLLVM\Builder::INT_NE, $__left, $rightValue);
                    goto return_bool;
                }
                break;
            case TYPE_PAIR_NATIVE_DOUBLE_NATIVE_BOOL:
                if (OpCode::TYPE_IDENTICAL === $opcode->type) {
                    $result = $this->context->getTypeFromString('int1')->constInt(0, false);
                    goto return_bool;
                }
                if (OpCode::TYPE_NOT_IDENTICAL === $opcode->type) {
                    $result = $this->context->getTypeFromString('int1')->constInt(1, false);
                    goto return_bool;
                }
                if (OpCode::TYPE_EQUAL === $opcode->type) {
                    $doubleTy = $this->context->getTypeFromString('double');
                    $zero = $doubleTy->constReal(0.0);
                    $result = $this->context->builder->fcmp(\PHPLLVM\Builder::REAL_OEQ, $leftValue, $zero);
                    goto return_bool;
                }
                if (OpCode::TYPE_NOT_EQUAL === $opcode->type) {
                    $doubleTy = $this->context->getTypeFromString('double');
                    $zero = $doubleTy->constReal(0.0);
                    $eq = $this->context->builder->fcmp(\PHPLLVM\Builder::REAL_OEQ, $leftValue, $zero);
                    $result = $this->context->builder->xor(
                        $eq,
                        $this->context->getTypeFromString('int1')->constInt(1, false)
                    );
                    goto return_bool;
                }
                break;
            case TYPE_PAIR_NATIVE_BOOL_NATIVE_DOUBLE:
                if (OpCode::TYPE_IDENTICAL === $opcode->type) {
                    $result = $this->context->getTypeFromString('int1')->constInt(0, false);
                    goto return_bool;
                }
                if (OpCode::TYPE_NOT_IDENTICAL === $opcode->type) {
                    $result = $this->context->getTypeFromString('int1')->constInt(1, false);
                    goto return_bool;
                }
                if (OpCode::TYPE_EQUAL === $opcode->type) {
                    $doubleTy = $this->context->getTypeFromString('double');
                    $zero = $doubleTy->constReal(0.0);
                    $result = $this->context->builder->fcmp(\PHPLLVM\Builder::REAL_OEQ, $rightValue, $zero);
                    goto return_bool;
                }
                if (OpCode::TYPE_NOT_EQUAL === $opcode->type) {
                    $doubleTy = $this->context->getTypeFromString('double');
                    $zero = $doubleTy->constReal(0.0);
                    $eq = $this->context->builder->fcmp(\PHPLLVM\Builder::REAL_OEQ, $rightValue, $zero);
                    $result = $this->context->builder->xor(
                        $eq,
                        $this->context->getTypeFromString('int1')->constInt(1, false)
                    );
                    goto return_bool;
                }
                break;
            case TYPE_PAIR_NATIVE_BOOL_NATIVE_BOOL:
                switch ($opcode->type) {
                    case OpCode::TYPE_IDENTICAL:
                    case OpCode::TYPE_EQUAL:
                        $result = $this->context->builder->icmp(\PHPLLVM\Builder::INT_EQ, $leftValue, $rightValue);
                        goto return_bool;
                    case OpCode::TYPE_NOT_IDENTICAL:
                    case OpCode::TYPE_NOT_EQUAL:
                        $result = $this->context->builder->icmp(\PHPLLVM\Builder::INT_NE, $leftValue, $rightValue);
                        goto return_bool;
                }
                break;
            case TYPE_PAIR_STRING_STRING:
                if (OpCode::TYPE_MODULO === $opcode->type) {
                    $leftLong = JitLongArg::lowerStringValue($this->context, $leftValue);
                    $rightLong = JitLongArg::lowerStringValue($this->context, $rightValue);
                    $result = JitNumericDivisionGuard::signedModulo($this->context, $leftLong, $rightLong);
                    goto return_long;
                }
                if (JitValueNumeric::isArithOpcode($opcode->type)) {
                    $leftLong = JitLongArg::lowerStringValue($this->context, $leftValue);
                    $rightLong = JitLongArg::lowerStringValue($this->context, $rightValue);
                    if (OpCode::TYPE_DIV === $opcode->type) {
                        $f64 = $this->context->getTypeFromString('double');
                        $leftDouble = $this->context->builder->siToFp($leftLong, $f64);
                        $rightDouble = $this->context->builder->siToFp($rightLong, $f64);
                        JitNumericDivisionGuard::emitZeroDoubleDivisorGuard(
                            $this->context,
                            $rightDouble,
                            'Division by zero'
                        );
                        $result = $this->context->builder->fdiv($leftDouble, $rightDouble);
                        goto return_double;
                    }
                    if (OpCode::TYPE_PLUS === $opcode->type) {
                        $result = $this->context->builder->addNoSignedWrap($leftLong, $rightLong);
                    } elseif (OpCode::TYPE_MINUS === $opcode->type) {
                        $result = $this->context->builder->subNoSignedWrap($leftLong, $rightLong);
                    } else {
                        $result = $this->context->builder->mulNoSignedWrap($leftLong, $rightLong);
                    }
                    goto return_long;
                }
                if (OpCode::TYPE_SPACESHIP === $opcode->type) {
                    $result = JitStringCompare::binaryOp($this->context, $opcode, $leftValue, $rightValue);
                    goto return_long;
                }
                $result = JitStringCompare::binaryOp($this->context, $opcode, $leftValue, $rightValue);
                goto return_bool;
        }
        if (Variable::TYPE_STRING === $leftType && Variable::TYPE_VALUE === $rightType) {
            if (OpCode::TYPE_IDENTICAL === $opcode->type || OpCode::TYPE_EQUAL === $opcode->type) {
                $result = JitStringCompare::identicalValueToString($this->context, $right, $leftValue);
                goto return_bool;
            }
            if (OpCode::TYPE_NOT_IDENTICAL === $opcode->type || OpCode::TYPE_NOT_EQUAL === $opcode->type) {
                $same = JitStringCompare::identicalValueToString($this->context, $right, $leftValue);
                $result = $this->context->builder->xor(
                    $same,
                    $this->context->getTypeFromString('int1')->constInt(1, false)
                );
                goto return_bool;
            }
            // NestedJIT string-offset fetch is TYPE_VALUE; `$ch < '0'` is STRING?VALUE (#27239).
            if (self::isOrderedCompareOpcode($opcode->type)) {
                Builtin\SpaceshipRuntime::ensureLinked($this->context);
                $tmp = JitValueBox::alloc($this->context);
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeString'),
                    JitValueBox::pointer($this->context, $tmp),
                    $leftValue
                );
                $cmp = Builtin\SpaceshipRuntime::callValueSpaceship(
                    $this->context,
                    JitValueBox::pointer($this->context, $tmp),
                    JitValueBox::valuePtrFromVariable($this->context, $right)
                );
                $result = JitValueCompare::boolFromSpaceshipCmp(
                    $this->context,
                    $opcode->type,
                    $cmp
                );
                goto return_bool;
            }
        }
        if (Variable::TYPE_VALUE === $leftType && Variable::TYPE_STRING === $rightType) {
            if (OpCode::TYPE_IDENTICAL === $opcode->type || OpCode::TYPE_EQUAL === $opcode->type) {
                $result = JitStringCompare::identicalStringToValue(
                    $this->context,
                    $rightValue,
                    $left
                );
                goto return_bool;
            }
            if (OpCode::TYPE_NOT_IDENTICAL === $opcode->type || OpCode::TYPE_NOT_EQUAL === $opcode->type) {
                $same = JitStringCompare::identicalStringToValue($this->context, $rightValue, $left);
                $result = $this->context->builder->xor(
                    $same,
                    $this->context->getTypeFromString('int1')->constInt(1, false)
                );
                goto return_bool;
            }
            // VALUE < STRING — e.g. `$date[$i] < '0'` after offset fetch (#27239 Strptime emit).
            if (self::isOrderedCompareOpcode($opcode->type)) {
                Builtin\SpaceshipRuntime::ensureLinked($this->context);
                $tmp = JitValueBox::alloc($this->context);
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeString'),
                    JitValueBox::pointer($this->context, $tmp),
                    $rightValue
                );
                $cmp = Builtin\SpaceshipRuntime::callValueSpaceship(
                    $this->context,
                    JitValueBox::valuePtrFromVariable($this->context, $left),
                    JitValueBox::pointer($this->context, $tmp)
                );
                $result = JitValueCompare::boolFromSpaceshipCmp(
                    $this->context,
                    $opcode->type,
                    $cmp
                );
                goto return_bool;
            }
        }
        if (Variable::TYPE_VALUE === $leftType && Variable::TYPE_VALUE === $rightType) {
            // Float-aware +/−/*/÷ on boxed values (#23471 mandelbrot AOT).
            if (JitValueNumeric::isArithOpcode($opcode->type)) {
                return JitValueNumeric::binaryValueValue(
                    $this->context,
                    $opcode->type,
                    $left,
                    $right
                );
            }
            if (OpCode::TYPE_MODULO === $opcode->type) {
                $leftLong = JitLongArg::lower($this->context, $left, 'binary op left operand');
                $rightLong = JitLongArg::lower($this->context, $right, 'binary op right operand');
                $result = JitNumericDivisionGuard::signedModulo($this->context, $leftLong, $rightLong);
                goto return_long;
            }
            switch ($opcode->type) {
                case OpCode::TYPE_BITWISE_AND:
                case OpCode::TYPE_BITWISE_OR:
                case OpCode::TYPE_BITWISE_XOR:
                case OpCode::TYPE_SHIFT_LEFT:
                case OpCode::TYPE_SHIFT_RIGHT:
                    $folded = $this->tryFoldCoreIntBitwise($opcode->type, $left, $right);
                    if (null !== $folded) {
                        $result = $this->context->getTypeFromString('int64')->constInt($folded, false);
                        goto return_long;
                    }
                    $leftLong = JitLongArg::lower($this->context, $left, 'binary op left operand');
                    $rightLong = JitLongArg::lower($this->context, $right, 'binary op right operand');
                    if (OpCode::TYPE_BITWISE_AND === $opcode->type) {
                        $result = $this->context->builder->bitwiseAnd($leftLong, $rightLong);
                    } elseif (OpCode::TYPE_BITWISE_OR === $opcode->type) {
                        $result = $this->context->builder->bitwiseOr($leftLong, $rightLong);
                    } elseif (OpCode::TYPE_BITWISE_XOR === $opcode->type) {
                        $result = $this->context->builder->bitwiseXor($leftLong, $rightLong);
                    } else {
                        $result = $this->emitGuardedIntShift($opcode->type, $leftLong, $rightLong);
                    }
                    goto return_long;
            }
            if (OpCode::TYPE_IDENTICAL === $opcode->type) {
                $result = JitValueCompare::identicalValueToValue($this->context, $left, $right);
                goto return_bool;
            }
            if (OpCode::TYPE_NOT_IDENTICAL === $opcode->type) {
                $result = JitValueCompare::notIdenticalValueToValue($this->context, $left, $right);
                goto return_bool;
            }
            if (OpCode::TYPE_EQUAL === $opcode->type || OpCode::TYPE_NOT_EQUAL === $opcode->type) {
                $equal = JitValueCompare::looseEqualOperands($this->context, $left, $right);
                if (OpCode::TYPE_NOT_EQUAL === $opcode->type) {
                    $result = $this->context->builder->xor(
                        $equal,
                        $this->context->getTypeFromString('int1')->constInt(1, false)
                    );
                } else {
                    $result = $equal;
                }
                goto return_bool;
            }
            if (self::isOrderedCompareOpcode($opcode->type)) {
                $result = JitValueCompare::orderedValueToValue(
                    $this->context,
                    $opcode->type,
                    $left,
                    $right
                );
                goto return_bool;
            }
        }
        if (Variable::TYPE_VALUE === $leftType && Variable::TYPE_VALUE !== $rightType) {
            if (Variable::TYPE_NATIVE_LONG === $rightType || Variable::TYPE_NATIVE_BOOL === $rightType) {
                // Strict compare before JitLongArg::lower — __value__readLong on bool tags segfaults (#8555).
                if (OpCode::TYPE_IDENTICAL === $opcode->type) {
                    $result = JitValueCompare::identicalToNative($this->context, $left, $right);
                    goto return_bool;
                }
                if (OpCode::TYPE_NOT_IDENTICAL === $opcode->type) {
                    $result = JitValueCompare::notIdenticalToNative($this->context, $left, $right);
                    goto return_bool;
                }
                if (JitValueNumeric::isArithOpcode($opcode->type)) {
                    return JitValueNumeric::binaryNativeLongValue(
                        $this->context,
                        $opcode->type,
                        $left,
                        $right,
                        $rightValue,
                        $rightType,
                        'right'
                    );
                }
                $leftLong = JitLongArg::lower($this->context, $left, 'binary op left operand');
                if (Variable::TYPE_NATIVE_BOOL === $rightType) {
                    $__right = $this->context->builder->zExt($rightValue, $leftLong->typeOf());
                } else {
                    $__right = $this->context->builder->intCast($rightValue, $leftLong->typeOf());
                }
                switch ($opcode->type) {
                    case OpCode::TYPE_MODULO:
                        $result = JitNumericDivisionGuard::signedModulo($this->context, $leftLong, $__right);
                        goto return_long;
                    case OpCode::TYPE_BITWISE_AND:
                        $result = $this->context->builder->bitwiseAnd($leftLong, $__right);
                        goto return_long;
                    case OpCode::TYPE_BITWISE_OR:
                        $result = $this->context->builder->bitwiseOr($leftLong, $__right);
                        goto return_long;
                    case OpCode::TYPE_BITWISE_XOR:
                        $result = $this->context->builder->bitwiseXor($leftLong, $__right);
                        goto return_long;
                    case OpCode::TYPE_SHIFT_LEFT:
                    case OpCode::TYPE_SHIFT_RIGHT:
                        $result = $this->emitGuardedIntShift($opcode->type, $leftLong, $__right);
                        goto return_long;
                    case OpCode::TYPE_EQUAL:
                        if (Variable::TYPE_NATIVE_LONG === $rightType) {
                            $result = JitValueCompare::looseEqualValueToNativeLong($this->context, $left, $__right);
                            goto return_bool;
                        }
                        break;
                    case OpCode::TYPE_NOT_EQUAL:
                        if (Variable::TYPE_NATIVE_LONG === $rightType) {
                            $result = JitValueCompare::notLooseEqualValueToNativeLong($this->context, $left, $__right);
                            goto return_bool;
                        }
                        break;
                }
            }
            if (Variable::TYPE_NATIVE_DOUBLE === $rightType) {
                if (OpCode::TYPE_IDENTICAL === $opcode->type) {
                    $result = JitValueCompare::identicalToNative($this->context, $left, $right);
                    goto return_bool;
                }
                if (OpCode::TYPE_NOT_IDENTICAL === $opcode->type) {
                    $result = JitValueCompare::notIdenticalToNative($this->context, $left, $right);
                    goto return_bool;
                }
                // Value-box doubles must use fadd/fsub/fmul/fdiv — integer add/sub/mul
                // on double operands fails LLVM module verify (#22990 pack NestedJIT).
                $leftDouble = $this->context->builder->call(
                    $this->context->lookupFunction('__value__readDouble'),
                    JitValueBox::valuePtrFromVariable($this->context, $left)
                );
                switch ($opcode->type) {
                    case OpCode::TYPE_PLUS:
                        $result = $this->context->builder->fadd($leftDouble, $rightValue);
                        goto return_double;
                    case OpCode::TYPE_MINUS:
                        $result = $this->context->builder->fsub($leftDouble, $rightValue);
                        goto return_double;
                    case OpCode::TYPE_MUL:
                        $result = $this->context->builder->fmul($leftDouble, $rightValue);
                        goto return_double;
                    case OpCode::TYPE_DIV:
                        JitNumericDivisionGuard::emitZeroDoubleDivisorGuard(
                            $this->context,
                            $rightValue,
                            'Division by zero'
                        );
                        $result = $this->context->builder->fdiv($leftDouble, $rightValue);
                        goto return_double;
                    case OpCode::TYPE_EQUAL:
                        $result = JitValueCompare::looseEqualValueToNativeDouble(
                            $this->context,
                            $left,
                            $rightValue
                        );
                        goto return_bool;
                    case OpCode::TYPE_NOT_EQUAL:
                        $result = JitValueCompare::notLooseEqualValueToNativeDouble(
                            $this->context,
                            $left,
                            $rightValue
                        );
                        goto return_bool;
                }
            }
            if (Variable::TYPE_NATIVE_LONG === $rightType && self::isOrderedCompareOpcode($opcode->type)) {
                $result = JitValueCompare::orderedValueToNativeLong(
                    $this->context,
                    $opcode->type,
                    $left,
                    $rightValue
                );
                goto return_bool;
            }
            if (Variable::TYPE_NATIVE_DOUBLE === $rightType && self::isOrderedCompareOpcode($opcode->type)) {
                $result = JitValueCompare::orderedValueToNativeDouble(
                    $this->context,
                    $opcode->type,
                    $left,
                    $rightValue
                );
                goto return_bool;
            }
            if (Variable::TYPE_OBJECT === $rightType) {
                if (OpCode::TYPE_IDENTICAL === $opcode->type) {
                    $result = JitValueCompare::identicalValueBoxToObject($this->context, $left, $right);
                    goto return_bool;
                }
                if (OpCode::TYPE_NOT_IDENTICAL === $opcode->type) {
                    $result = JitValueCompare::identicalValueBoxToObject($this->context, $left, $right);
                    $result = $this->context->builder->xor(
                        $result,
                        $this->context->getTypeFromString('int1')->constInt(1, false)
                    );
                    goto return_bool;
                }
            }
            if (OpCode::TYPE_IDENTICAL === $opcode->type) {
                $result = JitValueCompare::identicalToNative($this->context, $left, $right);
                goto return_bool;
            }
            if (OpCode::TYPE_NOT_IDENTICAL === $opcode->type) {
                $result = JitValueCompare::notIdenticalToNative($this->context, $left, $right);
                goto return_bool;
            }
        }
        if (Variable::TYPE_VALUE === $rightType && Variable::TYPE_VALUE !== $leftType) {
            if (Variable::TYPE_NATIVE_LONG === $leftType || Variable::TYPE_NATIVE_BOOL === $leftType) {
                if (OpCode::TYPE_IDENTICAL === $opcode->type) {
                    $result = JitValueCompare::identicalNativeToValue($this->context, $left, $right);
                    goto return_bool;
                }
                if (OpCode::TYPE_NOT_IDENTICAL === $opcode->type) {
                    $result = JitValueCompare::notIdenticalNativeToValue($this->context, $left, $right);
                    goto return_bool;
                }
                if (JitValueNumeric::isArithOpcode($opcode->type)) {
                    return JitValueNumeric::binaryNativeLongValue(
                        $this->context,
                        $opcode->type,
                        $left,
                        $right,
                        $leftValue,
                        $leftType,
                        'left'
                    );
                }
                $rightLong = JitLongArg::lower($this->context, $right, 'binary op right operand');
                if (Variable::TYPE_NATIVE_BOOL === $leftType) {
                    $__left = $this->context->builder->zExt($leftValue, $rightLong->typeOf());
                } else {
                    $__left = $this->context->builder->intCast($leftValue, $rightLong->typeOf());
                }
                switch ($opcode->type) {
                    case OpCode::TYPE_MODULO:
                        $result = JitNumericDivisionGuard::signedModulo($this->context, $__left, $rightLong);
                        goto return_long;
                    case OpCode::TYPE_BITWISE_AND:
                        $result = $this->context->builder->bitwiseAnd($__left, $rightLong);
                        goto return_long;
                    case OpCode::TYPE_BITWISE_OR:
                        $result = $this->context->builder->bitwiseOr($__left, $rightLong);
                        goto return_long;
                    case OpCode::TYPE_BITWISE_XOR:
                        $result = $this->context->builder->bitwiseXor($__left, $rightLong);
                        goto return_long;
                    case OpCode::TYPE_SHIFT_LEFT:
                    case OpCode::TYPE_SHIFT_RIGHT:
                        $result = $this->emitGuardedIntShift($opcode->type, $__left, $rightLong);
                        goto return_long;
                    case OpCode::TYPE_EQUAL:
                        if (Variable::TYPE_NATIVE_LONG === $leftType) {
                            $result = JitValueCompare::looseEqualNativeLongToValue($this->context, $__left, $right);
                            goto return_bool;
                        }
                        break;
                    case OpCode::TYPE_NOT_EQUAL:
                        if (Variable::TYPE_NATIVE_LONG === $leftType) {
                            $result = JitValueCompare::notLooseEqualNativeLongToValue($this->context, $__left, $right);
                            goto return_bool;
                        }
                        break;
                }
            }
            if (Variable::TYPE_NATIVE_DOUBLE === $leftType) {
                if (OpCode::TYPE_IDENTICAL === $opcode->type) {
                    $result = JitValueCompare::identicalNativeToValue($this->context, $left, $right);
                    goto return_bool;
                }
                if (OpCode::TYPE_NOT_IDENTICAL === $opcode->type) {
                    $result = JitValueCompare::notIdenticalNativeToValue($this->context, $left, $right);
                    goto return_bool;
                }
                // Peer VALUE⊙double above — float ops on boxed RHS (#22990).
                $rightDouble = $this->context->builder->call(
                    $this->context->lookupFunction('__value__readDouble'),
                    JitValueBox::valuePtrFromVariable($this->context, $right)
                );
                switch ($opcode->type) {
                    case OpCode::TYPE_PLUS:
                        $result = $this->context->builder->fadd($leftValue, $rightDouble);
                        goto return_double;
                    case OpCode::TYPE_MINUS:
                        $result = $this->context->builder->fsub($leftValue, $rightDouble);
                        goto return_double;
                    case OpCode::TYPE_MUL:
                        $result = $this->context->builder->fmul($leftValue, $rightDouble);
                        goto return_double;
                    case OpCode::TYPE_DIV:
                        JitNumericDivisionGuard::emitZeroDoubleDivisorGuard(
                            $this->context,
                            $rightDouble,
                            'Division by zero'
                        );
                        $result = $this->context->builder->fdiv($leftValue, $rightDouble);
                        goto return_double;
                    case OpCode::TYPE_EQUAL:
                        $result = JitValueCompare::looseEqualNativeDoubleToValue(
                            $this->context,
                            $leftValue,
                            $right
                        );
                        goto return_bool;
                    case OpCode::TYPE_NOT_EQUAL:
                        $result = JitValueCompare::notLooseEqualNativeDoubleToValue(
                            $this->context,
                            $leftValue,
                            $right
                        );
                        goto return_bool;
                }
            }
            if (Variable::TYPE_NATIVE_LONG === $leftType && self::isOrderedCompareOpcode($opcode->type)) {
                $result = JitValueCompare::orderedNativeLongToValue(
                    $this->context,
                    $opcode->type,
                    $leftValue,
                    $right
                );
                goto return_bool;
            }
            if (Variable::TYPE_NATIVE_DOUBLE === $leftType && self::isOrderedCompareOpcode($opcode->type)) {
                $result = JitValueCompare::orderedNativeDoubleToValue(
                    $this->context,
                    $opcode->type,
                    $leftValue,
                    $right
                );
                goto return_bool;
            }
            if (Variable::TYPE_OBJECT === $leftType) {
                if (OpCode::TYPE_IDENTICAL === $opcode->type) {
                    $result = JitValueCompare::identicalValueBoxToObject($this->context, $right, $left);
                    goto return_bool;
                }
                if (OpCode::TYPE_NOT_IDENTICAL === $opcode->type) {
                    $result = JitValueCompare::identicalValueBoxToObject($this->context, $right, $left);
                    $result = $this->context->builder->xor(
                        $result,
                        $this->context->getTypeFromString('int1')->constInt(1, false)
                    );
                    goto return_bool;
                }
            }
            if (OpCode::TYPE_IDENTICAL === $opcode->type) {
                $result = JitValueCompare::identicalNativeToValue($this->context, $left, $right);
                goto return_bool;
            }
            if (OpCode::TYPE_NOT_IDENTICAL === $opcode->type) {
                $result = JitValueCompare::notIdenticalNativeToValue($this->context, $left, $right);
                goto return_bool;
            }
        }
        if (Variable::TYPE_OBJECT === $leftType && $leftType === $rightType) {
            if (JitValueNumeric::isArithOpcode($opcode->type)
                && \PHPCompiler\CompilerVersion::supportsBcmath()
            ) {
                return \PHPCompiler\ext\bcmath\JitBcMathNumberOperators::binaryObjectObject(
                    $this->context,
                    $opcode->type,
                    $left,
                    $right
                );
            }
            if (OpCode::TYPE_SPACESHIP === $opcode->type) {
                Builtin\SpaceshipRuntime::ensureLinked($this->context);
                $result = Builtin\SpaceshipRuntime::callObjectCompareSpaceship(
                    $this->context,
                    $leftValue,
                    $rightValue
                );
                goto return_long;
            }
            if (self::isOrderedCompareOpcode($opcode->type)) {
                // Zend zend_compare_objects — same property walk as <=> (#25241).
                Builtin\SpaceshipRuntime::ensureLinked($this->context);
                $cmp = Builtin\SpaceshipRuntime::callObjectCompareSpaceship(
                    $this->context,
                    $leftValue,
                    $rightValue
                );
                $result = JitValueCompare::boolFromSpaceshipCmp(
                    $this->context,
                    $opcode->type,
                    $cmp
                );
                goto return_bool;
            }
            if (OpCode::TYPE_EQUAL === $opcode->type || OpCode::TYPE_NOT_EQUAL === $opcode->type) {
                $equal = JitValueCompare::looseEqualObjectPair($this->context, $leftValue, $rightValue);
                if (OpCode::TYPE_EQUAL === $opcode->type) {
                    $result = $equal;
                } else {
                    $result = $this->context->builder->xor(
                        $equal,
                        $this->context->getTypeFromString('int1')->constInt(1, false)
                    );
                }
                goto return_bool;
            }
            $voidp = $this->context->getTypeFromString('void')->pointerType(0);
            $leftNorm = $this->context->builder->pointerCast($leftValue, $voidp);
            $rightNorm = $this->context->builder->pointerCast($rightValue, $voidp);
            $sizeT = $this->context->getTypeFromString('size_t');
            $leftPtr = $this->context->builder->ptrToInt($leftNorm, $sizeT);
            $rightPtr = $this->context->builder->ptrToInt($rightNorm, $sizeT);
            if (OpCode::TYPE_IDENTICAL === $opcode->type) {
                $result = $this->context->builder->icmp(Builder::INT_EQ, $leftPtr, $rightPtr);
                goto return_bool;
            }
            if (OpCode::TYPE_NOT_IDENTICAL === $opcode->type) {
                $result = $this->context->builder->icmp(Builder::INT_NE, $leftPtr, $rightPtr);
                goto return_bool;
            }
        }
        if (OpCode::TYPE_EQUAL === $opcode->type || OpCode::TYPE_NOT_EQUAL === $opcode->type) {
            if (Variable::TYPE_VALUE === $leftType && Variable::TYPE_OBJECT === $rightType) {
                $readFn = $this->context->lookupFunction('__value__readObject');
                $leftObj = $this->context->builder->call(
                    $readFn,
                    $this->context->builder->pointerCast(
                        JitValueCompare::runtimeValuePtr($this->context, $left),
                        $readFn->getParam(0)->typeOf()
                    )
                );
                $equal = JitValueCompare::looseEqualObjectPair($this->context, $leftObj, $rightValue);
                $result = OpCode::TYPE_EQUAL === $opcode->type
                    ? $equal
                    : $this->context->builder->xor(
                        $equal,
                        $this->context->getTypeFromString('int1')->constInt(1, false)
                    );
                goto return_bool;
            }
            if (Variable::TYPE_OBJECT === $leftType && Variable::TYPE_VALUE === $rightType) {
                $readFn = $this->context->lookupFunction('__value__readObject');
                $rightObj = $this->context->builder->call(
                    $readFn,
                    $this->context->builder->pointerCast(
                        JitValueCompare::runtimeValuePtr($this->context, $right),
                        $readFn->getParam(0)->typeOf()
                    )
                );
                $equal = JitValueCompare::looseEqualObjectPair($this->context, $leftValue, $rightObj);
                $result = OpCode::TYPE_EQUAL === $opcode->type
                    ? $equal
                    : $this->context->builder->xor(
                        $equal,
                        $this->context->getTypeFromString('int1')->constInt(1, false)
                    );
                goto return_bool;
            }
        }
        if (OpCode::TYPE_SPACESHIP === $opcode->type) {
            if (JitValueBox::isValueOperand($left) && JitValueBox::isValueOperand($right)) {
                Builtin\SpaceshipRuntime::ensureLinked($this->context);
                $leftPtr = JitValueBox::valuePtrFromVariable($this->context, $left);
                $rightPtr = JitValueBox::valuePtrFromVariable($this->context, $right);
                $map = $this->context->structFieldMap['__value__'];
                $i8 = $this->context->getTypeFromString('int8');
                $objTag = $i8->constInt(Variable::TYPE_OBJECT, false);
                $leftKind = $this->context->builder->load(
                    $this->context->builder->structGep($leftPtr, $map['type'])
                );
                $rightKind = $this->context->builder->load(
                    $this->context->builder->structGep($rightPtr, $map['type'])
                );
                $bothObj = $this->context->builder->and(
                    $this->context->builder->icmp(Builder::INT_EQ, $leftKind, $objTag),
                    $this->context->builder->icmp(Builder::INT_EQ, $rightKind, $objTag)
                );
                $parentFn = BasicBlockHelper::parentFunction($this->context);
                $objBb = $parentFn->appendBasicBlock('val_spaceship_obj');
                $genBb = $parentFn->appendBasicBlock('val_spaceship_gen');
                $doneBb = $parentFn->appendBasicBlock('val_spaceship_done');
                $i64 = $this->context->getTypeFromString('int64');
                $resultSlot = BasicBlockHelper::entryAlloca($this->context, $i64);
                $this->context->builder->branchIf($bothObj, $objBb, $genBb);
                $this->context->builder->positionAtEnd($objBb);
                $leftObj = $this->context->builder->call(
                    $this->context->lookupFunction('__value__readObject'),
                    $leftPtr
                );
                $rightObj = $this->context->builder->call(
                    $this->context->lookupFunction('__value__readObject'),
                    $rightPtr
                );
                $objCmp = Builtin\SpaceshipRuntime::callObjectCompareSpaceship(
                    $this->context,
                    $leftObj,
                    $rightObj
                );
                $this->context->builder->store($objCmp, $resultSlot);
                $this->context->builder->branch($doneBb);
                $this->context->builder->positionAtEnd($genBb);
                $genCmp = Builtin\SpaceshipRuntime::callValueSpaceship($this->context, $leftPtr, $rightPtr);
                $this->context->builder->store($genCmp, $resultSlot);
                $this->context->builder->branch($doneBb);
                $this->context->builder->positionAtEnd($doneBb);
                $result = $this->context->builder->load($resultSlot);
                goto return_long;
            }
            if (Variable::TYPE_VALUE === $leftType && Variable::TYPE_OBJECT === $rightType) {
                Builtin\SpaceshipRuntime::ensureLinked($this->context);
                $boxed = JitValueBox::valuePtrFromVariable($this->context, $left);
                $tmp = $this->context->memory->malloc($this->context->getTypeFromString('__value__'));
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeObject'),
                    $this->context->builder->pointerCast($tmp, $this->context->getTypeFromString('__value__*')),
                    $rightValue
                );
                $result = Builtin\SpaceshipRuntime::callValueSpaceship(
                    $this->context,
                    $boxed,
                    $this->context->builder->pointerCast($tmp, $this->context->getTypeFromString('__value__*'))
                );
                goto return_long;
            }
            if (Variable::TYPE_OBJECT === $leftType && Variable::TYPE_VALUE === $rightType) {
                Builtin\SpaceshipRuntime::ensureLinked($this->context);
                $boxed = JitValueBox::valuePtrFromVariable($this->context, $right);
                $tmp = $this->context->memory->malloc($this->context->getTypeFromString('__value__'));
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeObject'),
                    $this->context->builder->pointerCast($tmp, $this->context->getTypeFromString('__value__*')),
                    $leftValue
                );
                $result = Builtin\SpaceshipRuntime::callValueSpaceship(
                    $this->context,
                    $this->context->builder->pointerCast($tmp, $this->context->getTypeFromString('__value__*')),
                    $boxed
                );
                goto return_long;
            }
            if (Variable::TYPE_OBJECT === $leftType && Variable::TYPE_STRING === $rightType) {
                Builtin\SpaceshipRuntime::ensureLinked($this->context);
                $objTmp = $this->context->memory->malloc($this->context->getTypeFromString('__value__'));
                $objPtr = $this->context->builder->pointerCast($objTmp, $this->context->getTypeFromString('__value__*'));
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeObject'),
                    $objPtr,
                    $leftValue
                );
                $strTmp = JitValueBox::alloc($this->context);
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeString'),
                    JitValueBox::pointer($this->context, $strTmp),
                    $rightValue
                );
                $result = Builtin\SpaceshipRuntime::callValueSpaceship(
                    $this->context,
                    $objPtr,
                    JitValueBox::pointer($this->context, $strTmp)
                );
                goto return_long;
            }
            if (Variable::TYPE_STRING === $leftType && Variable::TYPE_OBJECT === $rightType) {
                Builtin\SpaceshipRuntime::ensureLinked($this->context);
                $strTmp = JitValueBox::alloc($this->context);
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeString'),
                    JitValueBox::pointer($this->context, $strTmp),
                    $leftValue
                );
                $objTmp = $this->context->memory->malloc($this->context->getTypeFromString('__value__'));
                $objPtr = $this->context->builder->pointerCast($objTmp, $this->context->getTypeFromString('__value__*'));
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeObject'),
                    $objPtr,
                    $rightValue
                );
                $result = Builtin\SpaceshipRuntime::callValueSpaceship(
                    $this->context,
                    JitValueBox::pointer($this->context, $strTmp),
                    $objPtr
                );
                goto return_long;
            }
            if (Variable::TYPE_VALUE === $leftType && Variable::TYPE_STRING === $rightType) {
                Builtin\SpaceshipRuntime::ensureLinked($this->context);
                $boxedPtr = JitValueBox::valuePtrFromVariable($this->context, $left);
                $map = $this->context->structFieldMap['__value__'];
                $i8 = $this->context->getTypeFromString('int8');
                $objTag = $i8->constInt(Variable::TYPE_OBJECT, false);
                $kind = $this->context->builder->load(
                    $this->context->builder->structGep($boxedPtr, $map['type'])
                );
                $isObj = $this->context->builder->icmp(Builder::INT_EQ, $kind, $objTag);
                $i64 = $this->context->getTypeFromString('int64');
                $one = $i64->constInt(1, true);
                $parentFn = BasicBlockHelper::parentFunction($this->context);
                $oneBb = $parentFn->appendBasicBlock('val_spaceship_enum_str_one');
                $genBb = $parentFn->appendBasicBlock('val_spaceship_enum_str_gen');
                $doneBb = $parentFn->appendBasicBlock('val_spaceship_enum_str_done');
                $resultSlot = BasicBlockHelper::entryAlloca($this->context, $i64);
                $this->context->builder->branchIf($isObj, $oneBb, $genBb);
                $this->context->builder->positionAtEnd($oneBb);
                $this->context->builder->store($one, $resultSlot);
                $this->context->builder->branch($doneBb);
                $this->context->builder->positionAtEnd($genBb);
                $tmp = JitValueBox::alloc($this->context);
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeString'),
                    JitValueBox::pointer($this->context, $tmp),
                    $rightValue
                );
                $genCmp = Builtin\SpaceshipRuntime::callValueSpaceship(
                    $this->context,
                    $boxedPtr,
                    JitValueBox::pointer($this->context, $tmp)
                );
                $this->context->builder->store($genCmp, $resultSlot);
                $this->context->builder->branch($doneBb);
                $this->context->builder->positionAtEnd($doneBb);
                $result = $this->context->builder->load($resultSlot);
                goto return_long;
            }
            if (Variable::TYPE_STRING === $leftType && Variable::TYPE_VALUE === $rightType) {
                Builtin\SpaceshipRuntime::ensureLinked($this->context);
                $boxedPtr = JitValueBox::valuePtrFromVariable($this->context, $right);
                $map = $this->context->structFieldMap['__value__'];
                $i8 = $this->context->getTypeFromString('int8');
                $objTag = $i8->constInt(Variable::TYPE_OBJECT, false);
                $kind = $this->context->builder->load(
                    $this->context->builder->structGep($boxedPtr, $map['type'])
                );
                $isObj = $this->context->builder->icmp(Builder::INT_EQ, $kind, $objTag);
                $i64 = $this->context->getTypeFromString('int64');
                $one = $i64->constInt(1, true);
                $parentFn = BasicBlockHelper::parentFunction($this->context);
                $oneBb = $parentFn->appendBasicBlock('val_spaceship_str_enum_one');
                $genBb = $parentFn->appendBasicBlock('val_spaceship_str_enum_gen');
                $doneBb = $parentFn->appendBasicBlock('val_spaceship_str_enum_done');
                $resultSlot = BasicBlockHelper::entryAlloca($this->context, $i64);
                $this->context->builder->branchIf($isObj, $oneBb, $genBb);
                $this->context->builder->positionAtEnd($oneBb);
                $this->context->builder->store($one, $resultSlot);
                $this->context->builder->branch($doneBb);
                $this->context->builder->positionAtEnd($genBb);
                $tmp = JitValueBox::alloc($this->context);
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeString'),
                    JitValueBox::pointer($this->context, $tmp),
                    $leftValue
                );
                $genCmp = Builtin\SpaceshipRuntime::callValueSpaceship(
                    $this->context,
                    JitValueBox::pointer($this->context, $tmp),
                    $boxedPtr
                );
                $this->context->builder->store($genCmp, $resultSlot);
                $this->context->builder->branch($doneBb);
                $this->context->builder->positionAtEnd($doneBb);
                $result = $this->context->builder->load($resultSlot);
                goto return_long;
            }
            if (Variable::TYPE_VALUE === $leftType && Variable::TYPE_NATIVE_DOUBLE === $rightType) {
                $leftDouble = $this->context->builder->call(
                    $this->context->lookupFunction('__value__readDouble'),
                    JitValueBox::valuePtrFromVariable($this->context, $left)
                );
                $result = JitFloatCompare::spaceship($this->context, $leftDouble, $rightValue);
                goto return_long;
            }
            if (Variable::TYPE_NATIVE_DOUBLE === $leftType && Variable::TYPE_VALUE === $rightType) {
                $rightDouble = $this->context->builder->call(
                    $this->context->lookupFunction('__value__readDouble'),
                    JitValueBox::valuePtrFromVariable($this->context, $right)
                );
                $result = JitFloatCompare::spaceship($this->context, $leftValue, $rightDouble);
                goto return_long;
            }
        }
        if (Variable::TYPE_HASHTABLE === $leftType && $leftType === $rightType) {
            $lhs = $this->loadValue($left);
            $rhs = $this->loadValue($right);
            $trueVal = $this->context->getTypeFromString('int1')->constInt(1, false);
            if (OpCode::TYPE_IDENTICAL === $opcode->type) {
                $result = $this->context->builder->icmp(Builder::INT_EQ, $lhs, $rhs);
            } elseif (OpCode::TYPE_NOT_IDENTICAL === $opcode->type) {
                $result = $this->context->builder->icmp(Builder::INT_NE, $lhs, $rhs);
            } elseif (OpCode::TYPE_EQUAL === $opcode->type) {
                $result = JitValueCompare::looseEqualHashtablePair($this->context, $lhs, $rhs);
            } elseif (OpCode::TYPE_NOT_EQUAL === $opcode->type) {
                $same = JitValueCompare::looseEqualHashtablePair($this->context, $lhs, $rhs);
                $result = $this->context->builder->xor($same, $trueVal);
            } elseif (OpCode::TYPE_PLUS === $opcode->type) {
                return ArrayBuiltinHelper::arrayUnion($this->context, $left, $right);
            } else {
                $type = opcode_type_name($opcode->type);
                throw new \LogicException("Reached end of switch, can't handle binary operation yet: $type for hashtable pair");
            }
            goto return_bool;
        }
        if ((Variable::TYPE_HASHTABLE === $leftType || ArrayBuiltinHelper::isNativeArray($leftType))
            && Variable::TYPE_NATIVE_BOOL === $rightType) {
            $falseVal = $this->context->getTypeFromString('int1')->constInt(0, false);
            $trueVal = $this->context->getTypeFromString('int1')->constInt(1, false);
            if (OpCode::TYPE_IDENTICAL === $opcode->type) {
                $result = $falseVal;
                goto return_bool;
            }
            if (OpCode::TYPE_NOT_IDENTICAL === $opcode->type) {
                $result = $trueVal;
                goto return_bool;
            }
            if (OpCode::TYPE_EQUAL === $opcode->type) {
                $result = JitValueCompare::looseEqualArrayToBool(
                    $this->context,
                    $left,
                    $rightValue
                );
                goto return_bool;
            }
            if (OpCode::TYPE_NOT_EQUAL === $opcode->type) {
                $same = JitValueCompare::looseEqualArrayToBool(
                    $this->context,
                    $left,
                    $rightValue
                );
                $result = $this->context->builder->xor($same, $trueVal);
                goto return_bool;
            }
        }
        if (Variable::TYPE_NATIVE_BOOL === $leftType
            && (Variable::TYPE_HASHTABLE === $rightType || ArrayBuiltinHelper::isNativeArray($rightType))) {
            $falseVal = $this->context->getTypeFromString('int1')->constInt(0, false);
            $trueVal = $this->context->getTypeFromString('int1')->constInt(1, false);
            if (OpCode::TYPE_IDENTICAL === $opcode->type) {
                $result = $falseVal;
                goto return_bool;
            }
            if (OpCode::TYPE_NOT_IDENTICAL === $opcode->type) {
                $result = $trueVal;
                goto return_bool;
            }
            if (OpCode::TYPE_EQUAL === $opcode->type) {
                $result = JitValueCompare::looseEqualArrayToBool(
                    $this->context,
                    $right,
                    $leftValue
                );
                goto return_bool;
            }
            if (OpCode::TYPE_NOT_EQUAL === $opcode->type) {
                $same = JitValueCompare::looseEqualArrayToBool(
                    $this->context,
                    $right,
                    $leftValue
                );
                $result = $this->context->builder->xor($same, $trueVal);
                goto return_bool;
            }
        }
        $leftIsArray = Variable::TYPE_HASHTABLE === $leftType || ArrayBuiltinHelper::isNativeArray($leftType);
        $rightIsArray = Variable::TYPE_HASHTABLE === $rightType || ArrayBuiltinHelper::isNativeArray($rightType);
        if ($leftIsArray xor $rightIsArray) {
            $falseVal = $this->context->getTypeFromString('int1')->constInt(0, false);
            $trueVal = $this->context->getTypeFromString('int1')->constInt(1, false);
            if (OpCode::TYPE_EQUAL === $opcode->type || OpCode::TYPE_NOT_EQUAL === $opcode->type) {
                if ($leftIsArray) {
                    if (Variable::TYPE_NULL === $rightType) {
                        $same = JitValueCompare::looseEqualArrayToNull($this->context, $left);
                    } else {
                        $same = $falseVal;
                    }
                } elseif (Variable::TYPE_NULL === $leftType) {
                    $same = JitValueCompare::looseEqualArrayToNull($this->context, $right);
                } else {
                    $same = $falseVal;
                }
                $result = OpCode::TYPE_EQUAL === $opcode->type
                    ? $same
                    : $this->context->builder->xor($same, $trueVal);
                goto return_bool;
            }
        }
        if (Variable::TYPE_STRING === $leftType && Variable::TYPE_NATIVE_BOOL === $rightType) {
            $falseVal = $this->context->getTypeFromString('int1')->constInt(0, false);
            if (OpCode::TYPE_IDENTICAL === $opcode->type || OpCode::TYPE_EQUAL === $opcode->type) {
                $result = $falseVal;
                goto return_bool;
            }
            if (OpCode::TYPE_NOT_IDENTICAL === $opcode->type || OpCode::TYPE_NOT_EQUAL === $opcode->type) {
                $result = $this->context->getTypeFromString('int1')->constInt(1, false);
                goto return_bool;
            }
        }
        if (Variable::TYPE_STRING === $leftType && Variable::TYPE_NATIVE_LONG === $rightType) {
            if (OpCode::TYPE_IDENTICAL === $opcode->type) {
                $result = $this->context->getTypeFromString('int1')->constInt(0, false);
                goto return_bool;
            }
            if (OpCode::TYPE_NOT_IDENTICAL === $opcode->type) {
                $result = $this->context->getTypeFromString('int1')->constInt(1, false);
                goto return_bool;
            }
            if (OpCode::TYPE_EQUAL === $opcode->type) {
                $result = JitValueCompare::looseEqualStringToNativeLong(
                    $this->context,
                    $leftValue,
                    $rightValue
                );
                goto return_bool;
            }
            if (OpCode::TYPE_NOT_EQUAL === $opcode->type) {
                $same = JitValueCompare::looseEqualStringToNativeLong(
                    $this->context,
                    $leftValue,
                    $rightValue
                );
                $result = $this->context->builder->icmp(
                    Builder::INT_EQ,
                    $same,
                    $this->context->getTypeFromString('int1')->constInt(0, false)
                );
                goto return_bool;
            }
            if (OpCode::TYPE_SHIFT_LEFT === $opcode->type || OpCode::TYPE_SHIFT_RIGHT === $opcode->type) {
                $leftLong = JitLongArg::lowerStringValue($this->context, $leftValue);
                $__right = $this->context->builder->intCast($rightValue, $leftLong->typeOf());
                $result = $this->emitGuardedIntShift($opcode->type, $leftLong, $__right);
                goto return_long;
            }
            if (OpCode::TYPE_MODULO === $opcode->type) {
                $leftLong = JitLongArg::lowerStringValue($this->context, $leftValue);
                $__right = $this->context->builder->intCast($rightValue, $leftLong->typeOf());
                $result = JitNumericDivisionGuard::signedModulo($this->context, $leftLong, $__right);
                goto return_long;
            }
            if (OpCode::TYPE_PLUS === $opcode->type || OpCode::TYPE_MINUS === $opcode->type || OpCode::TYPE_MUL === $opcode->type) {
                $leftLong = JitLongArg::lowerStringValue($this->context, $leftValue);
                $__right = $this->context->builder->intCast($rightValue, $leftLong->typeOf());
                if (OpCode::TYPE_PLUS === $opcode->type) {
                    $result = $this->context->builder->addNoSignedWrap($leftLong, $__right);
                } elseif (OpCode::TYPE_MINUS === $opcode->type) {
                    $result = $this->context->builder->subNoSignedWrap($leftLong, $__right);
                } else {
                    $result = $this->context->builder->mulNoSignedWrap($leftLong, $__right);
                }
                goto return_long;
            }
            if (OpCode::TYPE_DIV === $opcode->type) {
                $f64 = $this->context->getTypeFromString('double');
                $leftDouble = $this->context->builder->siToFp(
                    JitLongArg::lowerStringValue($this->context, $leftValue),
                    $f64
                );
                $rightDouble = $this->context->builder->siToFp($rightValue, $f64);
                JitNumericDivisionGuard::emitZeroDoubleDivisorGuard(
                    $this->context,
                    $rightDouble,
                    'Division by zero'
                );
                $result = $this->context->builder->fdiv($leftDouble, $rightDouble);
                goto return_double;
            }
        }
        if (Variable::TYPE_NATIVE_LONG === $leftType && Variable::TYPE_STRING === $rightType) {
            if (OpCode::TYPE_IDENTICAL === $opcode->type) {
                $result = $this->context->getTypeFromString('int1')->constInt(0, false);
                goto return_bool;
            }
            if (OpCode::TYPE_NOT_IDENTICAL === $opcode->type) {
                $result = $this->context->getTypeFromString('int1')->constInt(1, false);
                goto return_bool;
            }
            if (OpCode::TYPE_EQUAL === $opcode->type) {
                $result = JitValueCompare::looseEqualStringToNativeLong(
                    $this->context,
                    $rightValue,
                    $leftValue
                );
                goto return_bool;
            }
            if (OpCode::TYPE_NOT_EQUAL === $opcode->type) {
                $same = JitValueCompare::looseEqualStringToNativeLong(
                    $this->context,
                    $rightValue,
                    $leftValue
                );
                $result = $this->context->builder->icmp(
                    Builder::INT_EQ,
                    $same,
                    $this->context->getTypeFromString('int1')->constInt(0, false)
                );
                goto return_bool;
            }
            if (OpCode::TYPE_SHIFT_LEFT === $opcode->type || OpCode::TYPE_SHIFT_RIGHT === $opcode->type) {
                $rightLong = JitLongArg::lowerStringValue($this->context, $rightValue);
                $__left = $this->context->builder->intCast($leftValue, $rightLong->typeOf());
                $result = $this->emitGuardedIntShift($opcode->type, $__left, $rightLong);
                goto return_long;
            }
            if (OpCode::TYPE_MODULO === $opcode->type) {
                $rightLong = JitLongArg::lowerStringValue($this->context, $rightValue);
                $__left = $this->context->builder->intCast($leftValue, $rightLong->typeOf());
                $result = JitNumericDivisionGuard::signedModulo($this->context, $__left, $rightLong);
                goto return_long;
            }
            if (OpCode::TYPE_PLUS === $opcode->type || OpCode::TYPE_MINUS === $opcode->type || OpCode::TYPE_MUL === $opcode->type) {
                $rightLong = JitLongArg::lowerStringValue($this->context, $rightValue);
                $__left = $this->context->builder->intCast($leftValue, $rightLong->typeOf());
                if (OpCode::TYPE_PLUS === $opcode->type) {
                    $result = $this->context->builder->addNoSignedWrap($__left, $rightLong);
                } elseif (OpCode::TYPE_MINUS === $opcode->type) {
                    $result = $this->context->builder->subNoSignedWrap($__left, $rightLong);
                } else {
                    $result = $this->context->builder->mulNoSignedWrap($__left, $rightLong);
                }
                goto return_long;
            }
            if (OpCode::TYPE_DIV === $opcode->type) {
                $f64 = $this->context->getTypeFromString('double');
                $leftDouble = $this->context->builder->siToFp($leftValue, $f64);
                $rightDouble = $this->context->builder->siToFp(
                    JitLongArg::lowerStringValue($this->context, $rightValue),
                    $f64
                );
                JitNumericDivisionGuard::emitZeroDoubleDivisorGuard(
                    $this->context,
                    $rightDouble,
                    'Division by zero'
                );
                $result = $this->context->builder->fdiv($leftDouble, $rightDouble);
                goto return_double;
            }
        }
        if (Variable::TYPE_STRING === $leftType && Variable::TYPE_NATIVE_DOUBLE === $rightType) {
            if (OpCode::TYPE_MODULO === $opcode->type) {
                $leftLong = JitLongArg::lowerStringValue($this->context, $leftValue);
                $i64 = $this->context->getTypeFromString('int64');
                $rightLong = $this->context->builder->fpToSi($rightValue, $i64);
                $result = JitNumericDivisionGuard::signedModulo($this->context, $leftLong, $rightLong);
                goto return_long;
            }
            $falseVal = $this->context->getTypeFromString('int1')->constInt(0, false);
            if (OpCode::TYPE_IDENTICAL === $opcode->type || OpCode::TYPE_EQUAL === $opcode->type) {
                $result = $falseVal;
                goto return_bool;
            }
            if (OpCode::TYPE_NOT_IDENTICAL === $opcode->type || OpCode::TYPE_NOT_EQUAL === $opcode->type) {
                $result = $this->context->getTypeFromString('int1')->constInt(1, false);
                goto return_bool;
            }
        }
        if (Variable::TYPE_NATIVE_DOUBLE === $leftType && Variable::TYPE_STRING === $rightType) {
            if (OpCode::TYPE_MODULO === $opcode->type) {
                $i64 = $this->context->getTypeFromString('int64');
                $leftLong = $this->context->builder->fpToSi($leftValue, $i64);
                $rightLong = JitLongArg::lowerStringValue($this->context, $rightValue);
                $result = JitNumericDivisionGuard::signedModulo($this->context, $leftLong, $rightLong);
                goto return_long;
            }
            $falseVal = $this->context->getTypeFromString('int1')->constInt(0, false);
            if (OpCode::TYPE_IDENTICAL === $opcode->type || OpCode::TYPE_EQUAL === $opcode->type) {
                $result = $falseVal;
                goto return_bool;
            }
            if (OpCode::TYPE_NOT_IDENTICAL === $opcode->type || OpCode::TYPE_NOT_EQUAL === $opcode->type) {
                $result = $this->context->getTypeFromString('int1')->constInt(1, false);
                goto return_bool;
            }
        }
        if (Variable::TYPE_NATIVE_BOOL === $leftType && Variable::TYPE_STRING === $rightType) {
            $falseVal = $this->context->getTypeFromString('int1')->constInt(0, false);
            if (OpCode::TYPE_IDENTICAL === $opcode->type || OpCode::TYPE_EQUAL === $opcode->type) {
                $result = $falseVal;
                goto return_bool;
            }
            if (OpCode::TYPE_NOT_IDENTICAL === $opcode->type || OpCode::TYPE_NOT_EQUAL === $opcode->type) {
                $result = $this->context->getTypeFromString('int1')->constInt(1, false);
                goto return_bool;
            }
        }
        if (Variable::TYPE_NULL === $leftType && JitValueBox::isValueOperand($right)) {
            if (OpCode::TYPE_IDENTICAL === $opcode->type || OpCode::TYPE_EQUAL === $opcode->type) {
                $result = JitValueCompare::valueBoxIsNull($this->context, $right);
                goto return_bool;
            }
            if (OpCode::TYPE_NOT_IDENTICAL === $opcode->type || OpCode::TYPE_NOT_EQUAL === $opcode->type) {
                $isNull = JitValueCompare::valueBoxIsNull($this->context, $right);
                $result = $this->context->builder->xor(
                    $isNull,
                    $this->context->getTypeFromString('int1')->constInt(1, false)
                );
                goto return_bool;
            }
        }
        if (JitValueBox::isValueOperand($left) && Variable::TYPE_NULL === $rightType) {
            if (OpCode::TYPE_IDENTICAL === $opcode->type || OpCode::TYPE_EQUAL === $opcode->type) {
                $result = JitValueCompare::valueBoxIsNull($this->context, $left);
                goto return_bool;
            }
            if (OpCode::TYPE_NOT_IDENTICAL === $opcode->type || OpCode::TYPE_NOT_EQUAL === $opcode->type) {
                $isNull = JitValueCompare::valueBoxIsNull($this->context, $left);
                $result = $this->context->builder->xor(
                    $isNull,
                    $this->context->getTypeFromString('int1')->constInt(1, false)
                );
                goto return_bool;
            }
        }
        if (OpCode::TYPE_IDENTICAL === $opcode->type || OpCode::TYPE_NOT_IDENTICAL === $opcode->type) {
            if (Variable::TYPE_VALUE === $leftType && Variable::TYPE_OBJECT === $rightType) {
                $result = JitValueCompare::identicalValueBoxToObject($this->context, $left, $right);
                if (OpCode::TYPE_NOT_IDENTICAL === $opcode->type) {
                    $result = $this->context->builder->xor(
                        $result,
                        $this->context->getTypeFromString('int1')->constInt(1, false)
                    );
                }
                goto return_bool;
            }
            if (Variable::TYPE_OBJECT === $leftType && Variable::TYPE_VALUE === $rightType) {
                $result = JitValueCompare::identicalValueBoxToObject($this->context, $right, $left);
                if (OpCode::TYPE_NOT_IDENTICAL === $opcode->type) {
                    $result = $this->context->builder->xor(
                        $result,
                        $this->context->getTypeFromString('int1')->constInt(1, false)
                    );
                }
                goto return_bool;
            }
        }
        if (OpCode::TYPE_PLUS === $opcode->type && $leftIsArray && $rightIsArray) {
            return ArrayBuiltinHelper::arrayUnion($this->context, $left, $right);
        }
        // Boxed arrays: TYPE_VALUE + hashtable / native list (bootstrap TYPE_PLUS pair 134/135).
        if (OpCode::TYPE_PLUS === $opcode->type) {
            if ($leftIsArray && Variable::TYPE_VALUE === $rightType) {
                return ArrayBuiltinHelper::arrayUnion($this->context, $left, $right);
            }
            if (Variable::TYPE_VALUE === $leftType && $rightIsArray) {
                return ArrayBuiltinHelper::arrayUnion($this->context, $left, $right);
            }
        }
        if (ArrayBuiltinHelper::isNativeArray($leftType) && $leftType === $rightType) {
            $trueVal = $this->context->getTypeFromString('int1')->constInt(1, false);
            if (OpCode::TYPE_EQUAL === $opcode->type) {
                $result = JitValueCompare::looseEqualNativeArrayPair($this->context, $left, $right);
                goto return_bool;
            }
            if (OpCode::TYPE_NOT_EQUAL === $opcode->type) {
                $same = JitValueCompare::looseEqualNativeArrayPair($this->context, $left, $right);
                $result = $this->context->builder->xor($same, $trueVal);
                goto return_bool;
            }
        }
        $type = opcode_type_name($opcode->type);
        throw new \LogicException("Reached end of switch, can't handle binary operation yet: $type for type pair {$leftType} and {$rightType}");
return_double:
        return new Variable($this->context, Variable::TYPE_NATIVE_DOUBLE, Variable::KIND_VALUE, $result);
return_long:
        return $this->nativeLongResultVariable($result);
return_bool:
        return new Variable($this->context, Variable::TYPE_NATIVE_BOOL, Variable::KIND_VALUE, $result);
    }

    public function loadValue(Variable $variable): PHPLLVM\Value {
        BasicBlockHelper::ensureOpenInsertBlock($this->context, 'load_value_cont');
        TypedPropertyUninitGuard::emitBeforeRead($this->context, $variable);
        if (null !== $variable->valueBoxAliasPtr) {
            $ptr = JitValueBox::normalizeValuePtr($this->context, $variable->valueBoxAliasPtr);
            switch ($variable->type) {
                case Variable::TYPE_NATIVE_BOOL:
                    return $this->context->builder->truncOrBitCast(
                        $this->context->builder->call(
                            $this->context->lookupFunction('__value__readLong'),
                            $ptr
                        ),
                        $this->context->getTypeFromString('int1')
                    );
                case Variable::TYPE_NATIVE_DOUBLE:
                    return $this->context->builder->call(
                        $this->context->lookupFunction('__value__readDouble'),
                        $ptr
                    );
                case Variable::TYPE_STRING:
                    return $this->context->builder->call(
                        $this->context->lookupFunction('__value__readString'),
                        $ptr
                    );
                case Variable::TYPE_OBJECT:
                    return $this->context->builder->call(
                        $this->context->lookupFunction('__value__readObject'),
                        $ptr
                    );
                case Variable::TYPE_HASHTABLE:
                    return $this->context->builder->call(
                        $this->context->lookupFunction('__value__readHashtable'),
                        $ptr
                    );
                case Variable::TYPE_VALUE:
                    // Alias is already __value__*; do not readLong (#24162).
                    return $ptr;
                case Variable::TYPE_NATIVE_LONG:
                default:
                    return $this->context->builder->call(
                        $this->context->lookupFunction('__value__readLong'),
                        $ptr
                    );
            }
        }
        if (null !== $variable->objectPropertySlot) {
            $loaded = $this->context->builder->load($variable->objectPropertySlot);
            if (null === $variable->objectPropertyType) {
                throw new \LogicException('objectPropertySlot requires objectPropertyType');
            }
            if (Variable::TYPE_HASHTABLE === $variable->objectPropertyType) {
                return $this->context->builder->pointerCast(
                    $loaded,
                    $this->context->getTypeFromString('__hashtable__*')
                );
            }
            if (Variable::TYPE_OBJECT === $variable->objectPropertyType) {
                return $this->context->builder->pointerCast(
                    $loaded,
                    $this->context->getTypeFromString('__object__*')
                );
            }
            if (Variable::TYPE_STRING === $variable->objectPropertyType) {
                return $this->context->builder->pointerCast(
                    $loaded,
                    $this->context->getTypeFromString('__string__*')
                );
            }
            if (Variable::TYPE_VALUE === $variable->objectPropertyType) {
                $valuePtr = $this->context->builder->pointerCast(
                    $loaded,
                    $this->context->getTypeFromString('__value__*')
                );
                if (Variable::TYPE_OBJECT === $variable->type) {
                    return $this->context->builder->call(
                        $this->context->lookupFunction('__value__readObject'),
                        $valuePtr
                    );
                }

                return $valuePtr;
            }

            if (Variable::TYPE_NATIVE_LONG === $variable->objectPropertyType) {
                $nativePtr = $this->context->builder->pointerCast(
                    $loaded,
                    $this->context->getTypeFromString('int64*')
                );

                return $this->context->builder->load($nativePtr);
            }
            if (Variable::TYPE_NATIVE_BOOL === $variable->objectPropertyType) {
                $nativePtr = $this->context->builder->pointerCast(
                    $loaded,
                    $this->context->getTypeFromString('int1*')
                );

                return $this->context->builder->load($nativePtr);
            }
            if (Variable::TYPE_NATIVE_DOUBLE === $variable->objectPropertyType) {
                $nativePtr = $this->context->builder->pointerCast(
                    $loaded,
                    $this->context->getTypeFromString('double*')
                );

                return $this->context->builder->load($nativePtr);
            }

            $llvmType = Variable::getStringType($variable->objectPropertyType);

            return $this->context->builder->pointerCast(
                $loaded,
                $this->context->getTypeFromString($llvmType)
            );
        }
        // `$r = &Class::$prop` aliases must reload the module global, not the fetch snapshot (#32036).
        if (null !== $variable->staticPropertyGlobal) {
            return $this->context->builder->load($variable->staticPropertyGlobal);
        }
        if ($variable->kind === Variable::KIND_VALUE) {
            if ($variable->functionStaticGlobal) {
                return $this->context->builder->load($variable->value);
            }

            return $variable->value;
        }
        return $this->context->builder->load($variable->value);
    }

    private static function isOrderedCompareOpcode(int $opcodeType): bool
    {
        return OpCode::TYPE_GREATER === $opcodeType
            || OpCode::TYPE_GREATER_OR_EQUAL === $opcodeType
            || OpCode::TYPE_SMALLER === $opcodeType
            || OpCode::TYPE_SMALLER_OR_EQUAL === $opcodeType;
    }

    private function operandJitType(Variable $var): int
    {
        if (null !== $var->objectPropertySlot && null !== $var->objectPropertyType) {
            if (Variable::TYPE_VALUE === $var->objectPropertyType) {
                return Variable::TYPE_VALUE;
            }

            return $var->objectPropertyType;
        }
        if (null !== $var->staticPropertyGlobal && null !== $var->staticPropertyType) {
            return $var->staticPropertyType;
        }

        return $var->type;
    }

    public function tryFoldCoreIntBitwise(int $opType, Variable $left, Variable $right): ?int
    {
        $leftInt = $this->tryResolveCoreIntConstant($left);
        $rightInt = $this->tryResolveCoreIntConstant($right);
        if (null === $leftInt || null === $rightInt) {
            return null;
        }

        // Negative shift count is a runtime ArithmeticError — do not fold (#21912).
        if ((OpCode::TYPE_SHIFT_LEFT === $opType || OpCode::TYPE_SHIFT_RIGHT === $opType)
            && $rightInt < 0) {
            return null;
        }

        return match ($opType) {
            OpCode::TYPE_BITWISE_AND => $leftInt & $rightInt,
            OpCode::TYPE_BITWISE_OR => $leftInt | $rightInt,
            OpCode::TYPE_BITWISE_XOR => $leftInt ^ $rightInt,
            OpCode::TYPE_SHIFT_LEFT => $leftInt << $rightInt,
            OpCode::TYPE_SHIFT_RIGHT => $leftInt >> $rightInt,
            default => null,
        };
    }

    /** Zend shift_left/right_function: negative count → catchable ArithmeticError (#21912). */
    private function emitGuardedIntShift(int $opType, $leftLong, $rightLong)
    {
        JitNumericDivisionGuard::emitNegativeBitShiftCountGuard($this->context, $rightLong);
        if (OpCode::TYPE_SHIFT_LEFT === $opType) {
            return $this->context->builder->shl($leftLong, $rightLong);
        }

        return $this->context->builder->aShr($leftLong, $rightLong);
    }

    /** Zend shift_left/right_function: bool operands promote to int (false→0, true→1). */
    private function emitShiftWithBoolOperands(
        OpCode $opcode,
        $leftValue,
        $rightValue,
        int $leftType,
        int $rightType
    ) {
        $i64 = $this->context->getTypeFromString('int64');
        if (Variable::TYPE_NATIVE_BOOL === $leftType) {
            $leftLong = $this->context->builder->zExt($leftValue, $i64);
        } else {
            $leftLong = $leftValue;
        }
        if (Variable::TYPE_NATIVE_BOOL === $rightType) {
            $rightLong = $this->context->builder->zExt($rightValue, $i64);
        } else {
            $rightLong = $this->context->builder->intCast($rightValue, $leftLong->typeOf());
        }

        return $this->emitGuardedIntShift($opcode->type, $leftLong, $rightLong);
    }

    /** Zend shift_left/right_function: float operands truncate to int before shift (#5270). */
    private function emitShiftWithFloatOperands(
        OpCode $opcode,
        $leftValue,
        $rightValue,
        int $leftType,
        int $rightType
    ) {
        $i64 = $this->context->getTypeFromString('int64');
        if (Variable::TYPE_NATIVE_DOUBLE === $leftType) {
            $leftLong = $this->context->builder->fpToSi($leftValue, $i64);
        } else {
            $leftLong = $this->context->builder->intCast($leftValue, $i64);
        }
        if (Variable::TYPE_NATIVE_DOUBLE === $rightType) {
            $rightLong = $this->context->builder->fpToSi($rightValue, $i64);
        } else {
            $rightLong = $this->context->builder->intCast($rightValue, $leftLong->typeOf());
        }

        return $this->emitGuardedIntShift($opcode->type, $leftLong, $rightLong);
    }

    private function tryResolveCoreIntConstant(Variable $var): ?int
    {
        if (Variable::KIND_VALUE !== $var->kind) {
            return null;
        }
        $lib = $this->context->llvm->lib;
        if (Variable::TYPE_NATIVE_LONG === $var->type
            && null !== $lib->LLVMIsAConstantInt($var->value->value)
        ) {
            return (int) $lib->LLVMConstIntGetZExtValue($var->value->value);
        }
        if (Variable::TYPE_NATIVE_BOOL === $var->type
            && null !== $lib->LLVMIsAConstantInt($var->value->value)
        ) {
            return (int) $lib->LLVMConstIntGetZExtValue($var->value->value);
        }

        $literal = $var->compileTimeString ?? null;
        if (null !== $literal && is_numeric($literal) && ((string) (int) $literal) === $literal) {
            return (int) $literal;
        }

        $name = $var->compileTimeConstantName ?? null;
        if (null === $name) {
            return null;
        }
        $lookup = strtolower($name);
        $stdlibInt = StdlibConstants::coreIntByName($lookup);
        if (null !== $stdlibInt) {
            return $stdlibInt;
        }
        if (null === $this->context->runtime->vmContext) {
            return null;
        }
        $phpVar = $this->context->runtime->vmContext->constantFetch($name);
        if (null !== $phpVar && \PHPCompiler\VM\Variable::TYPE_INTEGER === $phpVar->type) {
            return $phpVar->toInt();
        }

        return null;
    }

    /** Preserve folded int literals for compile-time consumers (#19090). */
    private function nativeLongResultVariable($result): Variable
    {
        $var = new Variable($this->context, Variable::TYPE_NATIVE_LONG, Variable::KIND_VALUE, $result);
        $lib = $this->context->llvm->lib;
        if (null !== $lib->LLVMIsAConstantInt($result->value)) {
            $var->compileTimeLong = (int) $lib->LLVMConstIntGetZExtValue($result->value);
        }

        return $var;
    }

}