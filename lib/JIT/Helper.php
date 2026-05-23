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
        switch ($var->type) {
            case Variable::TYPE_NATIVE_LONG:
                switch ($opcode->type) {
                    case OpCode::TYPE_UNARY_MINUS:
                        $result = $this->context->builder->negate($varValue);
                        goto return_long;
                }
                break;
            case Variable::TYPE_NATIVE_DOUBLE:
                switch ($opcode->type) {
                    case OpCode::TYPE_UNARY_MINUS:
                        $result = $this->context->builder->fNegate($varValue);
                        goto return_double;
                }
                break;
        }
        $type = opcode_type_name($opcode->type);
        throw new \LogicException("Reached end of switch, can't handle unary operation yet: $type for type {$var->type}");
return_double:
        return new Variable($this->context, Variable::TYPE_NATIVE_DOUBLE, Variable::KIND_VALUE, $result);
return_long:
        return new Variable($this->context, Variable::TYPE_NATIVE_LONG, Variable::KIND_VALUE, $result);
return_bool:
        return new Variable($this->context, Variable::TYPE_NATIVE_BOOL, Variable::KIND_VALUE, $result);
    }

    public function binaryOp(OpCode $opcode, Variable $left, Variable $right): Variable {
        $leftValue = $this->loadValue($left);
        $rightValue = $this->loadValue($right);
        $leftType = $left->type;
        $rightType = $right->type;
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
                        $result = $this->context->builder->fdiv($leftValue, $rightValue);
                        goto return_double;
                    case OpCode::TYPE_MODULO:
                        $result = $this->context->builder->frem($leftValue, $rightValue);
                        goto return_double;
                    case OpCode::TYPE_BITWISE_AND:
                    case OpCode::TYPE_BITWISE_OR:
                    case OpCode::TYPE_BITWISE_XOR:
                    case OpCode::TYPE_SHIFT_LEFT:
                    case OpCode::TYPE_SHIFT_RIGHT:
                        break;
                    case OpCode::TYPE_GREATER_OR_EQUAL:
                        $result = $this->context->builder->fcmp(Builder::REAL_OGE, $leftValue, $rightValue);
                        goto return_bool;
                    case OpCode::TYPE_SMALLER_OR_EQUAL:
                        $result = $this->context->builder->fcmp(Builder::REAL_OLE, $leftValue, $rightValue);
                        goto return_bool;
                    case OpCode::TYPE_GREATER:
                        $result = $this->context->builder->fcmp(Builder::REAL_OGT, $leftValue, $rightValue);
                        goto return_bool;
                    case OpCode::TYPE_SMALLER:
                        $result = $this->context->builder->fcmp(Builder::REAL_OLT, $leftValue, $rightValue);
                        goto return_bool;
                    case OpCode::TYPE_IDENTICAL:
                    case OpCode::TYPE_EQUAL:
                        $result = $this->context->builder->fcmp(Builder::REAL_OEQ, $leftValue, $rightValue);
                        goto return_bool;
                    case OpCode::TYPE_NOT_IDENTICAL:
                    case OpCode::TYPE_NOT_EQUAL:
                        $result = $this->context->builder->fcmp(Builder::REAL_ONE, $leftValue, $rightValue);
                        goto return_bool;
                    case OpCode::TYPE_SPACESHIP:
                        $lt = $this->context->builder->fcmp(Builder::REAL_OLT, $leftValue, $rightValue);
                        $gt = $this->context->builder->fcmp(Builder::REAL_OGT, $leftValue, $rightValue);
                        $ty = $leftValue->typeOf();
                        $negOne = $ty->constInt(-1, true);
                        $one = $ty->constInt(1, true);
                        $zero = $ty->constInt(0, false);
                        $result = $this->context->builder->select($gt, $one, $this->context->builder->select($lt, $negOne, $zero));
                        goto return_long;
                }
                break;
            case TYPE_PAIR_NATIVE_LONG_NATIVE_LONG:
                switch ($opcode->type) {
                    case OpCode::TYPE_MUL:
                        $__right = $this->context->builder->intCast($rightValue, $leftValue->typeOf());
                            
                            
                        

                        

                        

                        

                        

                        

                        
                            $result = $this->context->builder->mulNoSignedWrap($leftValue, $__right);
    
                        goto return_long;
                    case OpCode::TYPE_PLUS:
                        $__right = $this->context->builder->intCast($rightValue, $leftValue->typeOf());
                            
                            
                        

                        

                        

                        

                        
                            $result = $this->context->builder->addNoSignedWrap($leftValue, $__right);
    
                        goto return_long;
                    case OpCode::TYPE_MINUS:
                        $__right = $this->context->builder->intCast($rightValue, $leftValue->typeOf());
                            
                            
                        

                        

                        

                        

                        

                        
                            $result = $this->context->builder->subNoSignedWrap($leftValue, $__right);
    
                        goto return_long;
                    case OpCode::TYPE_DIV:
                        $__right = $this->context->builder->intCast($rightValue, $leftValue->typeOf());
                            
                            
                        

                        

                        

                        

                        

                        

                        

                        
                            $result = $this->context->builder->signedDiv($leftValue, $__right);
    
                        goto return_long;
                    case OpCode::TYPE_MODULO:
                        $__right = $this->context->builder->intCast($rightValue, $leftValue->typeOf());
                            
                            
                        

                        

                        

                        

                        

                        

                        

                        

                        
                            $result = $this->context->builder->signedRem($leftValue, $__right);
    
                        goto return_long;
                    case OpCode::TYPE_BITWISE_AND:
                        $__right = $this->context->builder->intCast($rightValue, $leftValue->typeOf());
                            
                            
                        

                        $result = $this->context->builder->bitwiseAnd($leftValue, $__right);
    
                        goto return_long;
                    case OpCode::TYPE_BITWISE_OR:
                        $__right = $this->context->builder->intCast($rightValue, $leftValue->typeOf());
                            
                            
                        

                        

                        $result = $this->context->builder->bitwiseOr($leftValue, $__right);
    
                        goto return_long;
                    case OpCode::TYPE_BITWISE_XOR:
                        $__right = $this->context->builder->intCast($rightValue, $leftValue->typeOf());
                            
                            
                        

                        

                        

                        $result = $this->context->builder->bitwiseXor($leftValue, $__right);
    
                        goto return_long;
                    case OpCode::TYPE_SHIFT_LEFT:
                        $__right = $this->context->builder->intCast($rightValue, $leftValue->typeOf());
                        $result = $this->context->builder->shl($leftValue, $__right);
                        goto return_long;
                    case OpCode::TYPE_SHIFT_RIGHT:
                        $__right = $this->context->builder->intCast($rightValue, $leftValue->typeOf());
                        $result = $this->context->builder->aShr($leftValue, $__right);
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
                            
                            
                        

                        

                        

                        

                        

                        

                        

                        

                        

                        

                        

                        

                        

                        $result = $this->context->builder->icmp(\PHPLLVM\Builder::INT_EQ, $leftValue, $__right);
    
                        goto return_bool;
                    case OpCode::TYPE_NOT_IDENTICAL:
                    case OpCode::TYPE_NOT_EQUAL:
                        $__right = $this->context->builder->intCast($rightValue, $leftValue->typeOf());
                            
                            
                        

                        

                        

                        

                        

                        

                        

                        

                        

                        

                        

                        

                        

                        

                        

                        $result = $this->context->builder->icmp(\PHPLLVM\Builder::INT_NE, $leftValue, $__right);
    
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
        }
        if (Variable::TYPE_VALUE === $leftType && Variable::TYPE_STRING === $rightType) {
            if (OpCode::TYPE_IDENTICAL === $opcode->type || OpCode::TYPE_EQUAL === $opcode->type) {
                $result = JitStringCompare::identicalStringToValue($this->context, $rightValue, $left);
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
        }
        if (Variable::TYPE_VALUE === $leftType && Variable::TYPE_VALUE === $rightType) {
            if (OpCode::TYPE_PLUS === $opcode->type) {
                $leftPtr = Variable::KIND_VARIABLE === $left->kind ? $left->value : $this->loadValue($left);
                $rightPtr = Variable::KIND_VARIABLE === $right->kind ? $right->value : $this->loadValue($right);
                $readLong = $this->context->lookupFunction('__value__readLong');
                $leftLong = $this->context->builder->call($readLong, $leftPtr);
                $rightLong = $this->context->builder->call($readLong, $rightPtr);
                $result = $this->context->builder->addNoSignedWrap($leftLong, $rightLong);
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
                $identical = JitValueCompare::identicalValueToValue($this->context, $left, $right);
                if (OpCode::TYPE_NOT_EQUAL === $opcode->type) {
                    $result = $this->context->builder->xor(
                        $identical,
                        $this->context->getTypeFromString('int1')->constInt(1, false)
                    );
                } else {
                    $result = $identical;
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
            if (Variable::TYPE_NATIVE_LONG === $rightType) {
                $leftPtr = Variable::KIND_VARIABLE === $left->kind ? $left->value : $this->loadValue($left);
                $leftLong = $this->context->builder->call(
                    $this->context->lookupFunction('__value__readLong'),
                    $leftPtr
                );
                $__right = $this->context->builder->intCast($rightValue, $leftLong->typeOf());
                switch ($opcode->type) {
                    case OpCode::TYPE_PLUS:
                        $result = $this->context->builder->addNoSignedWrap($leftLong, $__right);
                        goto return_long;
                    case OpCode::TYPE_MINUS:
                        $result = $this->context->builder->subNoSignedWrap($leftLong, $__right);
                        goto return_long;
                    case OpCode::TYPE_MUL:
                        $result = $this->context->builder->mulNoSignedWrap($leftLong, $__right);
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
                        $result = $this->context->builder->shl($leftLong, $__right);
                        goto return_long;
                    case OpCode::TYPE_SHIFT_RIGHT:
                        $result = $this->context->builder->aShr($leftLong, $__right);
                        goto return_long;
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
            if (OpCode::TYPE_IDENTICAL === $opcode->type) {
                if (Variable::TYPE_NATIVE_BOOL === $rightType) {
                    $valuePtr = Variable::KIND_VARIABLE === $left->kind
                        ? $left->value
                        : $this->loadValue($left);
                    $stored = $this->context->builder->call(
                        $this->context->lookupFunction('__value__readLong'),
                        $valuePtr
                    );
                    $result = $this->context->builder->icmp(
                        Builder::INT_EQ,
                        $stored,
                        $stored->typeOf()->constInt(0, false)
                    );
                    goto return_bool;
                }
                $result = JitValueCompare::identicalToNative($this->context, $left, $right);
                goto return_bool;
            }
            if (OpCode::TYPE_NOT_IDENTICAL === $opcode->type) {
                if (Variable::TYPE_NATIVE_BOOL === $rightType) {
                    $valuePtr = Variable::KIND_VARIABLE === $left->kind
                        ? $left->value
                        : $this->loadValue($left);
                    $stored = $this->context->builder->call(
                        $this->context->lookupFunction('__value__readLong'),
                        $valuePtr
                    );
                    $result = $this->context->builder->icmp(
                        Builder::INT_NE,
                        $stored,
                        $stored->typeOf()->constInt(0, false)
                    );
                    goto return_bool;
                }
                $result = JitValueCompare::notIdenticalToNative($this->context, $left, $right);
                goto return_bool;
            }
        }
        if (Variable::TYPE_VALUE === $rightType && Variable::TYPE_VALUE !== $leftType) {
            if (Variable::TYPE_NATIVE_LONG === $leftType) {
                $rightPtr = Variable::KIND_VARIABLE === $right->kind ? $right->value : $this->loadValue($right);
                $rightLong = $this->context->builder->call(
                    $this->context->lookupFunction('__value__readLong'),
                    $rightPtr
                );
                $__left = $this->context->builder->intCast($leftValue, $rightLong->typeOf());
                switch ($opcode->type) {
                    case OpCode::TYPE_PLUS:
                        $result = $this->context->builder->addNoSignedWrap($__left, $rightLong);
                        goto return_long;
                    case OpCode::TYPE_MINUS:
                        $result = $this->context->builder->subNoSignedWrap($__left, $rightLong);
                        goto return_long;
                    case OpCode::TYPE_MUL:
                        $result = $this->context->builder->mulNoSignedWrap($__left, $rightLong);
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
                        $result = $this->context->builder->shl($__left, $rightLong);
                        goto return_long;
                    case OpCode::TYPE_SHIFT_RIGHT:
                        $result = $this->context->builder->aShr($__left, $rightLong);
                        goto return_long;
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
            if (OpCode::TYPE_IDENTICAL === $opcode->type) {
                if (Variable::TYPE_NATIVE_BOOL === $leftType) {
                    $valuePtr = Variable::KIND_VARIABLE === $right->kind
                        ? $right->value
                        : $this->loadValue($right);
                    $stored = $this->context->builder->call(
                        $this->context->lookupFunction('__value__readLong'),
                        $valuePtr
                    );
                    $result = $this->context->builder->icmp(
                        Builder::INT_EQ,
                        $stored,
                        $stored->typeOf()->constInt(0, false)
                    );
                    goto return_bool;
                }
                $result = JitValueCompare::identicalNativeToValue($this->context, $left, $right);
                goto return_bool;
            }
            if (OpCode::TYPE_NOT_IDENTICAL === $opcode->type) {
                if (Variable::TYPE_NATIVE_BOOL === $leftType) {
                    $valuePtr = Variable::KIND_VARIABLE === $right->kind
                        ? $right->value
                        : $this->loadValue($right);
                    $stored = $this->context->builder->call(
                        $this->context->lookupFunction('__value__readLong'),
                        $valuePtr
                    );
                    $result = $this->context->builder->icmp(
                        Builder::INT_NE,
                        $stored,
                        $stored->typeOf()->constInt(0, false)
                    );
                    goto return_bool;
                }
                $result = JitValueCompare::notIdenticalNativeToValue($this->context, $left, $right);
                goto return_bool;
            }
        }
        if (Variable::TYPE_HASHTABLE === $leftType && $leftType === $rightType) {
            $lhs = $this->loadValue($left);
            $rhs = $this->loadValue($right);
            if (OpCode::TYPE_IDENTICAL === $opcode->type) {
                $result = $this->context->builder->icmp(Builder::INT_EQ, $lhs, $rhs);
            } elseif (OpCode::TYPE_NOT_IDENTICAL === $opcode->type) {
                $result = $this->context->builder->icmp(Builder::INT_NE, $lhs, $rhs);
            } else {
                $type = opcode_type_name($opcode->type);
                throw new \LogicException("Reached end of switch, can't handle binary operation yet: $type for hashtable pair");
            }
            goto return_bool;
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
        $type = opcode_type_name($opcode->type);
        throw new \LogicException("Reached end of switch, can't handle binary operation yet: $type for type pair {$leftType} and {$rightType}");
return_double:
        return new Variable($this->context, Variable::TYPE_NATIVE_DOUBLE, Variable::KIND_VALUE, $result);
return_long:
        return new Variable($this->context, Variable::TYPE_NATIVE_LONG, Variable::KIND_VALUE, $result);
return_bool:
        return new Variable($this->context, Variable::TYPE_NATIVE_BOOL, Variable::KIND_VALUE, $result);
    }

    public function loadValue(Variable $variable): PHPLLVM\Value {
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

            return $this->context->builder->pointerCast(
                $loaded,
                $this->context->getTypeFromString('__value__*')
            );
        }
        if ($variable->kind === Variable::KIND_VALUE) {
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

}