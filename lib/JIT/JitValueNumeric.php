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
 * when either operand is a double; `/` yields int when long/long division is exact.
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

    /**
     * Boxed bool (TYPE_NATIVE_BOOL). zend_operators.c IS_TRUE/IS_FALSE ++/-- is a no-op (#33761).
     */
    public static function valueIsBool(Context $context, Variable $boxed): Value
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
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );
    }

    /** Boxed string stored as JIT TYPE_STRING (132), not VM TYPE_STRING (4). */
    public static function valueIsJitString(Context $context, Variable $boxed): Value
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
            $i8->constInt(Variable::TYPE_STRING, false)
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

        // Bool before float: JIT TYPE_NATIVE_BOOL (2) collides with VM TYPE_FLOAT (2).
        // Matching VM float first made boxed true → readDouble → 0.0 (#34674 / peer #34667).
        $context->builder->positionAtEnd($afterStr);
        $boolBlock = BasicBlockHelper::append($context, 'vbox_to_d_bool');
        $afterBool = BasicBlockHelper::append($context, 'vbox_to_d_after_bool');
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );
        $context->builder->branchIf($isBool, $boolBlock, $afterBool);

        $context->builder->positionAtEnd($boolBlock);
        $boolByte = JitValueBox::readBoolByte($context, $valuePtr);
        $boolDbl = $context->builder->uiToFp($boolByte, $f64);
        $boolEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        // Only TYPE_NATIVE_DOUBLE (3) — do not OR VM TYPE_FLOAT (2); that steals bools.
        $context->builder->positionAtEnd($afterBool);
        $dblBlock = BasicBlockHelper::append($context, 'vbox_to_d_dbl');
        $numBlock = BasicBlockHelper::append($context, 'vbox_to_d_num');
        $isNativeDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $context->builder->branchIf($isNativeDouble, $dblBlock, $numBlock);

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
        $phi->addIncoming($boolDbl, $boolEnd);
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

        if (OpCode::TYPE_DIV === $opType) {
            $isDouble = self::valueIsDouble($context, $boxed);
            $floatBlock = BasicBlockHelper::append($context, 'native_vbox_div_float');
            $longBlock = BasicBlockHelper::append($context, 'native_vbox_div_long');
            $doneBlock = BasicBlockHelper::append($context, 'native_vbox_div_done');
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
                JitLongDiv::writeBoxedBinary($context, $nativeLong, $boxedLong, $slotPtr);
            } else {
                JitLongDiv::writeBoxedBinary($context, $boxedLong, $nativeLong, $slotPtr);
            }
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

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
        // valueBoxToDouble: bool before float (TYPE_NATIVE_BOOL=2 steals VM TYPE_FLOAT).
        // __value__readDouble on a bool box yields 0.0 → true/2=0 and 5/true Division by zero (#34682).
        $boxedDouble = self::valueBoxToDouble($context, $boxed);
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

        $eitherPromote = OpCode::TYPE_DIV === $opType
            ? $context->builder->or(
                self::valueIsDouble($context, $left),
                self::valueIsDouble($context, $right)
            )
            : $context->builder->or(
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
        if (OpCode::TYPE_DIV === $opType) {
            JitNumericDivisionGuard::emitZeroDoubleDivisorGuard($context, $rd, 'Division by zero');
            $fres = $context->builder->fdiv($ld, $rd);
        } else {
            $fres = self::emitDoubleOp($context, $opType, $ld, $rd);
        }
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $slotPtr,
            $fres
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($longBlock);
        // Bool boxes are TYPE_NATIVE_BOOL (2); __value__readLong returns 0 — use JitLongArg (#34678).
        $ll = JitLongArg::lower($context, $left, 'binary op left');
        $rl = JitLongArg::lower($context, $right, 'binary op right');
        if (OpCode::TYPE_DIV === $opType) {
            JitLongDiv::writeBoxedBinary($context, $ll, $rl, $slotPtr);
        } elseif (JitLongArithOverflow::supportsOpcode($opType)) {
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
                throw new \LogicException('JitValueNumeric: int/int `/` must use JitLongDiv::writeBoxedBinary');
            default:
                throw new \LogicException('JitValueNumeric: unsupported long opcode');
        }
    }
}
