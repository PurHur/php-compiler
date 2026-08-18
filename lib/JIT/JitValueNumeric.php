<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\OpCode;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Float-aware arithmetic on boxed {@see __value__} operands (#23471).
 *
 * VALUE×VALUE and native⊙VALUE used to always call {@see JitLongArg::lower}, which
 * truncates doubles (2.5×2.5 → 4). Match php-src add/mul/sub/div: promote to double
 * when either operand is a double; `/` always yields double.
 */
final class JitValueNumeric
{
    public static function isArithOpcode(int $opType): bool
    {
        return OpCode::TYPE_PLUS === $opType
            || OpCode::TYPE_MINUS === $opType
            || OpCode::TYPE_MUL === $opType
            || OpCode::TYPE_DIV === $opType;
    }

    public static function valueIsDouble(Context $context, Variable $boxed): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $boxed);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');

        return $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
    }

    public static function valueIsString(Context $context, Variable $boxed): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $boxed);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $jitStr = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $vmStr = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(\PHPCompiler\VM\Variable::TYPE_STRING, false)
        );

        return $context->builder->or($jitStr, $vmStr);
    }

    /**
     * VALUE ⊙ VALUE for + − * /.
     *
     * php-src: Zend/zend_operators.c — add_function / mul_function / …
     */
    public static function binaryValueValue(
        Context $context,
        int $opType,
        Variable $left,
        Variable $right
    ): Variable {
        // Compile-time boxed arrays: keep union on TYPE_PLUS.
        if (OpCode::TYPE_PLUS === $opType
            && ($left->valueBoxHashtable || $right->valueBoxHashtable)
        ) {
            return ArrayBuiltinHelper::arrayUnion($context, $left, $right);
        }

        // BcMath\Number do_operation when either box holds a Number (#24683).
        if (self::isArithOpcode($opType)
            && \PHPCompiler\CompilerVersion::supportsBcmath()
        ) {
            return \PHPCompiler\ext\bcmath\JitBcMathNumberOperators::binaryValueValue(
                $context,
                $opType,
                $left,
                $right
            );
        }

        return self::emitBoxedNumericResult($context, $opType, $left, $right);
    }

    /**
     * convert_scalar_to_number → double (zend_operators.c, #32325).
     *
     * `__value__readDouble` returns 0.0 for TYPE_STRING boxes; numeric strings must go through strtod.
     */
    public static function valueBoxToDouble(Context $context, Variable $boxed): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $boxed);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $f64 = $context->getTypeFromString('double');
        $done = BasicBlockHelper::append($context, 'vbox_to_d_done');

        $strBlock = BasicBlockHelper::append($context, 'vbox_to_d_str');
        $afterStr = BasicBlockHelper::append($context, 'vbox_to_d_after_str');
        $isString = $context->builder->or(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_STRING, false)
            ),
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(\PHPCompiler\VM\Variable::TYPE_STRING, false)
            )
        );
        $context->builder->branchIf($isString, $strBlock, $afterStr);
        $context->builder->positionAtEnd($strBlock);
        $strPtr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
        $strDbl = JitLongArg::lowerStringToDouble($context, $strPtr);
        $strEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterStr);
        $dblBlock = BasicBlockHelper::append($context, 'vbox_to_d_dbl');
        $numBlock = BasicBlockHelper::append($context, 'vbox_to_d_num');
        $isNativeDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $isVmFloat = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(\PHPCompiler\VM\Variable::TYPE_FLOAT, false)
        );
        $isFloat = $context->builder->or($isNativeDouble, $isVmFloat);
        $context->builder->branchIf($isFloat, $dblBlock, $numBlock);

        $context->builder->positionAtEnd($dblBlock);
        $dblVal = $context->builder->call(
            $context->lookupFunction('__value__readDouble'),
            $valuePtr
        );
        $dblEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($numBlock);
        $numLong = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $valuePtr
        );
        $numDbl = $context->builder->siToFp($numLong, $f64);
        $numEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($f64, 'vbox_to_d_phi');
        $phi->addIncoming($strDbl, $strEnd);
        $phi->addIncoming($dblVal, $dblEnd);
        $phi->addIncoming($numDbl, $numEnd);

        return $phi;
    }

    /** Public wrapper for Number/scalar dual-path (#24683). */
    public static function emitBoxedNumericResultPublic(
        Context $context,
        int $opType,
        Variable $left,
        Variable $right
    ): Variable {
        return self::emitBoxedNumericResult($context, $opType, $left, $right);
    }

    /**
     * Native long/bool ⊙ VALUE (or VALUE ⊙ native) for + − * /.
     *
     * @param 'left'|'right' $nativeSide which operand is the native long/bool
     */
    public static function binaryNativeLongValue(
        Context $context,
        int $opType,
        Variable $left,
        Variable $right,
        Value $nativeValue,
        int $nativeType,
        string $nativeSide
    ): Variable {
        $boxed = 'left' === $nativeSide ? $right : $left;
        $nativeLong = $nativeValue;
        if (Variable::TYPE_NATIVE_BOOL === $nativeType) {
            $nativeLong = $context->builder->zExt(
                $nativeValue,
                $context->getTypeFromString('int64')
            );
        } elseif (Variable::TYPE_NATIVE_LONG === $nativeType) {
            $nativeLong = $context->builder->intCast(
                $nativeValue,
                $context->getTypeFromString('int64')
            );
        }

        $slot = JitValueBox::alloc($context);
        $slotPtr = JitValueBox::pointer($context, $slot);

        // PHP `/` is always float (zend_div).
        if (OpCode::TYPE_DIV === $opType) {
            self::writeNativeLongValueFloat(
                $context,
                $opType,
                $nativeLong,
                $boxed,
                $nativeSide,
                $slotPtr
            );

            return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
        }

        $isDouble = self::valueIsDouble($context, $boxed);
        $floatBlock = BasicBlockHelper::append($context, 'native_vbox_arith_float');
        $longBlock = BasicBlockHelper::append($context, 'native_vbox_arith_long');
        $doneBlock = BasicBlockHelper::append($context, 'native_vbox_arith_done');
        $context->builder->branchIf($isDouble, $floatBlock, $longBlock);

        $context->builder->positionAtEnd($floatBlock);
        self::writeNativeLongValueFloat(
            $context,
            $opType,
            $nativeLong,
            $boxed,
            $nativeSide,
            $slotPtr
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($longBlock);
        $boxedLong = JitLongArg::lower($context, $boxed, 'binary op boxed operand');
        if ('left' === $nativeSide) {
            if (JitLongArithOverflow::supportsOpcode($opType)) {
                JitLongArithOverflow::writeBoxedBinary($context, $opType, $nativeLong, $boxedLong, $slotPtr);
            } else {
                $longResult = self::emitLongOp($context, $opType, $nativeLong, $boxedLong);
                $context->builder->call(
                    $context->lookupFunction('__value__writeLong'),
                    $slotPtr,
                    $longResult
                );
            }
        } else {
            if (JitLongArithOverflow::supportsOpcode($opType)) {
                JitLongArithOverflow::writeBoxedBinary($context, $opType, $boxedLong, $nativeLong, $slotPtr);
            } else {
                $longResult = self::emitLongOp($context, $opType, $boxedLong, $nativeLong);
                $context->builder->call(
                    $context->lookupFunction('__value__writeLong'),
                    $slotPtr,
                    $longResult
                );
            }
        }
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
    }

    private static function writeNativeLongValueFloat(
        Context $context,
        int $opType,
        Value $nativeLong,
        Variable $boxed,
        string $nativeSide,
        Value $slotPtr
    ): void {
        $f64 = $context->getTypeFromString('double');
        $nativeDouble = $context->builder->siToFp($nativeLong, $f64);
        $boxedDouble = $context->builder->call(
            $context->lookupFunction('__value__readDouble'),
            JitValueBox::valuePtrFromVariable($context, $boxed)
        );
        if ('left' === $nativeSide) {
            $fres = self::emitDoubleOp($context, $opType, $nativeDouble, $boxedDouble);
        } else {
            $fres = self::emitDoubleOp($context, $opType, $boxedDouble, $nativeDouble);
        }
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $slotPtr,
            $fres
        );
    }

    private static function emitBoxedNumericResult(
        Context $context,
        int $opType,
        Variable $left,
        Variable $right
    ): Variable {
        $leftPtr = JitValueBox::valuePtrFromVariable($context, $left);
        $rightPtr = JitValueBox::valuePtrFromVariable($context, $right);
        $slot = JitValueBox::alloc($context);
        $slotPtr = JitValueBox::pointer($context, $slot);

        // `/` is always float in PHP (zend_div). Numeric strings use strtod (#32325).
        if (OpCode::TYPE_DIV === $opType) {
            $ld = self::valueBoxToDouble($context, $left);
            $rd = self::valueBoxToDouble($context, $right);
            JitNumericDivisionGuard::emitZeroDoubleDivisorGuard($context, $rd, 'Division by zero');
            $fres = $context->builder->fdiv($ld, $rd);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fres
            );

            return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
        }

        $eitherPromote = $context->builder->or(
            $context->builder->or(
                self::valueIsDouble($context, $left),
                self::valueIsDouble($context, $right)
            ),
            $context->builder->or(
                self::valueIsString($context, $left),
                self::valueIsString($context, $right)
            )
        );
        $floatBlock = BasicBlockHelper::append($context, 'vbox_vbox_arith_float');
        $longBlock = BasicBlockHelper::append($context, 'vbox_vbox_arith_long');
        $doneBlock = BasicBlockHelper::append($context, 'vbox_vbox_arith_done');
        $context->builder->branchIf($eitherPromote, $floatBlock, $longBlock);

        $context->builder->positionAtEnd($floatBlock);
        $ld = self::valueBoxToDouble($context, $left);
        $rd = self::valueBoxToDouble($context, $right);
        $fres = self::emitDoubleOp($context, $opType, $ld, $rd);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $slotPtr,
            $fres
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($longBlock);
        $ll = $context->builder->call($context->lookupFunction('__value__readLong'), $leftPtr);
        $rl = $context->builder->call($context->lookupFunction('__value__readLong'), $rightPtr);
        if (JitLongArithOverflow::supportsOpcode($opType)) {
            JitLongArithOverflow::writeBoxedBinary($context, $opType, $ll, $rl, $slotPtr);
        } else {
            $lres = self::emitLongOp($context, $opType, $ll, $rl);
            $context->builder->call(
                $context->lookupFunction('__value__writeLong'),
                $slotPtr,
                $lres
            );
        }
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
    }

    private static function emitDoubleOp(Context $context, int $opType, Value $left, Value $right): Value
    {
        switch ($opType) {
            case OpCode::TYPE_PLUS:
                return $context->builder->fadd($left, $right);
            case OpCode::TYPE_MINUS:
                return $context->builder->fsub($left, $right);
            case OpCode::TYPE_MUL:
                return $context->builder->fmul($left, $right);
            case OpCode::TYPE_DIV:
                JitNumericDivisionGuard::emitZeroDoubleDivisorGuard(
                    $context,
                    $right,
                    'Division by zero'
                );

                return $context->builder->fdiv($left, $right);
            default:
                throw new \LogicException('JitValueNumeric: unsupported float opcode');
        }
    }

    private static function emitLongOp(Context $context, int $opType, Value $left, Value $right): Value
    {
        switch ($opType) {
            case OpCode::TYPE_PLUS:
                return $context->builder->addNoSignedWrap($left, $right);
            case OpCode::TYPE_MINUS:
                return $context->builder->subNoSignedWrap($left, $right);
            case OpCode::TYPE_MUL:
                return $context->builder->mulNoSignedWrap($left, $right);
            case OpCode::TYPE_DIV:
                // Callers early-return DIV onto the float path (zend_div). Do not sdiv here (#31968).
                throw new \LogicException('JitValueNumeric: int/int `/` must use emitDoubleOp');
            default:
                throw new \LogicException('JitValueNumeric: unsupported long opcode');
        }
    }
}
