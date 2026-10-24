<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\pow;
use PHPCompiler\JIT\Builtin\MathFpow;
use PHPCompiler\JIT\Builtin\PowIntRuntime;
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

        // BcMath\Number do_operation — hook from ext/bcmath/Module::jitInit (#36204 / #24683).
        if (self::isArithOpcode($opType) && null !== $context->arithBinaryValueValueHook) {
            return ($context->arithBinaryValueValueHook)($context, $opType, $left, $right);
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

        return self::valueBoxToDoubleWithTypeByte(
            $context,
            $valuePtr,
            self::loadTypeByte($context, $valuePtr)
        );
    }

    /**
     * @param Value $valuePtr pointer to {@see __value__}
     * @param Value $typeByte cached `__value__::type` byte for $valuePtr
     */
    private static function valueBoxToDoubleWithTypeByte(
        Context $context,
        Value $valuePtr,
        Value $typeByte
    ): Value {
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
     * Boxed ** when at least one operand aliases a property slot (#35978).
     *
     * {@see emitBoxedNumericResult} long path; JitPow::invokeBoxedRuntimeDispatch mis-reads
     * property temps that still carry objectPropertySlot metadata.
     */
    public static function powValueOperands(
        Context $context,
        Variable $base,
        Variable $exp
    ): Value {
        $basePtr = JitValueBox::valuePtrFromVariable($context, $base);
        $expPtr = JitValueBox::valuePtrFromVariable($context, $exp);
        $baseTypeByte = self::loadTypeByte($context, $basePtr);
        $expTypeByte = self::loadTypeByte($context, $expPtr);
        [$basePtr, $baseTypeByte, $expPtr, $expTypeByte] = self::normalizeNumericStrings(
            $context,
            '**',
            $basePtr,
            $baseTypeByte,
            $expPtr,
            $expTypeByte
        );
        $slot = JitValueBox::alloc($context);
        $slotPtr = JitValueBox::pointer($context, $slot);

        $eitherPromote = $context->builder->or(
            self::typeByteIsDouble($context, $baseTypeByte),
            self::typeByteIsDouble($context, $expTypeByte)
        );
        $floatBlock = BasicBlockHelper::append($context, 'vbox_pow_float');
        $longBlock = BasicBlockHelper::append($context, 'vbox_pow_long');
        $doneBlock = BasicBlockHelper::append($context, 'vbox_pow_done');
        $context->builder->branchIf($eitherPromote, $floatBlock, $longBlock);

        $context->builder->positionAtEnd($floatBlock);
        $double = $context->getTypeFromString('double');
        $baseD = self::valueBoxToDoubleWithTypeByte($context, $basePtr, $baseTypeByte);
        $expD = self::valueBoxToDoubleWithTypeByte($context, $expPtr, $expTypeByte);
        $fres = MathFpow::invoke($context, $baseD, $expD);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $slotPtr,
            $fres
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($longBlock);
        // Exact int, or php-src's double continuation on overflow (was (int) pow(double)).
        $i64 = $context->getTypeFromString('int64');
        Builtin\PowLongLlvm::call(
            $context,
            $slotPtr,
            self::boxToLong($context, $basePtr, $baseTypeByte),
            self::boxToLong($context, $expPtr, $expTypeByte)
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $slotPtr;
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
        $leftTypeByte = self::loadTypeByte($context, $leftPtr);
        $rightTypeByte = self::loadTypeByte($context, $rightPtr);
        [$leftPtr, $leftTypeByte, $rightPtr, $rightTypeByte] = self::normalizeNumericStrings(
            $context,
            self::operatorSymbol($opType),
            $leftPtr,
            $leftTypeByte,
            $rightPtr,
            $rightTypeByte
        );
        $slot = JitValueBox::alloc($context);
        $slotPtr = JitValueBox::pointer($context, $slot);

        $eitherPromote = $context->builder->or(
            self::typeByteIsDouble($context, $leftTypeByte),
            self::typeByteIsDouble($context, $rightTypeByte)
        );
        $floatBlock = BasicBlockHelper::append($context, 'vbox_vbox_arith_float');
        $longBlock = BasicBlockHelper::append($context, 'vbox_vbox_arith_long');
        $doneBlock = BasicBlockHelper::append($context, 'vbox_vbox_arith_done');
        $context->builder->branchIf($eitherPromote, $floatBlock, $longBlock);

        $context->builder->positionAtEnd($floatBlock);
        $ld = self::valueBoxToDoubleWithTypeByte($context, $leftPtr, $leftTypeByte);
        $rd = self::valueBoxToDoubleWithTypeByte($context, $rightPtr, $rightTypeByte);
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
        $ll = self::boxToLong($context, $leftPtr, $leftTypeByte);
        $rl = self::boxToLong($context, $rightPtr, $rightTypeByte);
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

    private static function loadTypeByte(Context $context, Value $valuePtr): Value
    {
        $map = $context->structFieldMap['__value__'];

        return $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
    }

    private static function typeByteIsDouble(Context $context, Value $typeByte): Value
    {
        $i8 = $context->getTypeFromString('int8');

        return $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
    }

    private static function typeByteIsString(Context $context, Value $typeByte): Value
    {
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
     * php-src numeric-string handling for a boxed binary arithmetic op
     * (zendi_smart_str… / ZEND_TRY_BINARY_OP*_OBJECT… → zendi_try_convert_scalar_to_number):
     * a string operand becomes IS_LONG/IS_DOUBLE per `_is_numeric_string_ex`; a numeric
     * prefix with trailing garbage warns "A non-numeric value encountered"; a non-numeric
     * string throws "Unsupported operand types: <l> <op> <r>". Non-string operands pass
     * through untouched.
     *
     * @return array{Value, Value, Value, Value} [leftPtr, leftType, rightPtr, rightType]
     */
    private static function normalizeNumericStrings(
        Context $context,
        string $op,
        Value $leftPtr,
        Value $leftTypeByte,
        Value $rightPtr,
        Value $rightTypeByte
    ): array {
        $leftName = self::typeNameForTypeByte($context, $leftTypeByte);
        $rightName = self::typeNameForTypeByte($context, $rightTypeByte);
        [$leftPtr, $leftTypeByte] = self::normalizeOneNumericString($context, $op, $leftPtr, $leftTypeByte, $leftName, $rightName);
        [$rightPtr, $rightTypeByte] = self::normalizeOneNumericString($context, $op, $rightPtr, $rightTypeByte, $leftName, $rightName);

        return [$leftPtr, $leftTypeByte, $rightPtr, $rightTypeByte];
    }

    /** @return array{Value, Value} */
    private static function normalizeOneNumericString(
        Context $context,
        string $op,
        Value $ptr,
        Value $typeByte,
        Value $leftName,
        Value $rightName
    ): array {
        $b = $context->builder;
        $tmp = JitValueBox::pointer($context, JitValueBox::alloc($context));
        $origBlock = $b->getInsertBlock();
        $strBlock = BasicBlockHelper::append($context, 'numstr_convert');
        $join = BasicBlockHelper::append($context, 'numstr_join');
        $b->branchIf(self::typeByteIsString($context, $typeByte), $strBlock, $join);

        $b->positionAtEnd($strBlock);
        $status = Builtin\NumericStringLlvm::call(
            $context,
            $b->call($context->lookupFunction('__value__readString'), $ptr),
            $tmp
        );
        $i32 = $context->getTypeFromString('int32');
        $errBlock = BasicBlockHelper::append($context, 'numstr_type_error');
        $notErr = BasicBlockHelper::append($context, 'numstr_numeric');
        $b->branchIf(
            $b->icmp(Builder::INT_EQ, $status, $i32->constInt(Builtin\NumericStringLlvm::NON_NUMERIC, false)),
            $errBlock,
            $notErr
        );
        $b->positionAtEnd($errBlock);
        $msg = self::concatStrings(
            $context,
            ClassNameTable::emitConcat($context, 'Unsupported operand types: ', $leftName, ' '.$op.' '),
            $rightName
        );
        ExceptionBridge::emitTypeErrorAndAbortWithStringValue($context, $msg);
        if (null === $b->getInsertBlock()?->getTerminator()) {
            $context->llvm->lib->LLVMBuildUnreachable($b->builder);
        }

        $b->positionAtEnd($notErr);
        $warnBlock = BasicBlockHelper::append($context, 'numstr_warn');
        $converted = BasicBlockHelper::append($context, 'numstr_converted');
        $b->branchIf(
            $b->icmp(Builder::INT_EQ, $status, $i32->constInt(Builtin\NumericStringLlvm::LEADING, false)),
            $warnBlock,
            $converted
        );
        $b->positionAtEnd($warnBlock);
        self::emitNonNumericWarning($context);
        $b->branch($converted);
        $b->positionAtEnd($converted);
        $tmpType = self::loadTypeByte($context, $tmp);
        $b->branch($join);

        $b->positionAtEnd($join);
        $ptrPhi = $b->phi($ptr->typeOf());
        $ptrPhi->addIncoming($ptr, $origBlock);
        $ptrPhi->addIncoming($tmp, $converted);
        $typePhi = $b->phi($typeByte->typeOf());
        $typePhi->addIncoming($typeByte, $origBlock);
        $typePhi->addIncoming($tmpType, $converted);

        return [$ptrPhi, $typePhi];
    }

    /** zend_zval_type_name() for the scalar/array tags a value box can carry. */
    private static function typeNameForTypeByte(Context $context, Value $typeByte): Value
    {
        $b = $context->builder;
        $i8 = $context->getTypeFromString('int8');
        $name = static fn (string $n): Value => $b->load($context->constantStringFromString($n));
        $kind = $b->bitwiseAnd($typeByte, $i8->constInt(0x7f, false));
        $result = $name('mixed');
        foreach ([
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_NATIVE_LONG => 'int',
            Variable::TYPE_NATIVE_BOOL => 'bool',
            Variable::TYPE_NATIVE_DOUBLE => 'float',
            Variable::TYPE_STRING & 0x7f => 'string',
            Variable::TYPE_HASHTABLE & 0x7f => 'array',
        ] as $tag => $label) {
            $result = $b->select($b->icmp(Builder::INT_EQ, $kind, $i8->constInt($tag, false)), $name($label), $result);
        }

        return $result;
    }

    private static function concatStrings(Context $context, Value $a, Value $b2): Value
    {
        // a . b via the 3-part concat with an empty literal prefix/suffix around b, prefixed by a.
        $b = $context->builder;
        $map = $context->structFieldMap['__string__'];
        $i8p = $context->getTypeFromString('int8*');
        $la = $b->load($b->structGep($a, $map['length']));
        $lb = $b->load($b->structGep($b2, $map['length']));
        $out = $b->call($context->lookupFunction('__string__alloc'), $b->add($la, $lb));
        $dst = $b->pointerCast($b->structGep($out, $map['value']), $i8p);
        LibcExtern::ensureMemcpyDecl($context);
        $memcpy = $context->lookupFunction('memcpy');
        $b->call($memcpy, $dst, $b->pointerCast($b->structGep($a, $map['value']), $i8p), $la);
        $b->call($memcpy, $b->gep($dst, $la), $b->pointerCast($b->structGep($b2, $map['value']), $i8p), $lb);
        return $out;
    }

    private static function emitNonNumericWarning(Context $context): void
    {
        $message = 'A non-numeric value encountered';
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $context->builder->pointerCast($context->constantFromString($message), $i8p),
            $context->getTypeFromString('size_t')->constInt(\strlen($message), false),
            $i32->constInt(\PHPCompiler\VM\ErrorReporter::E_WARNING, false),
            $context->builder->pointerCast($context->constantFromString($context->jitAotEntryScriptPath), $i8p),
            $i32->constInt(max(0, $context->callSiteLine), false)
        );
    }

    /** Long payload of a normalized numeric box (null → 0, bool, else __value__readLong). */
    private static function boxToLong(Context $context, Value $ptr, Value $typeByte): Value
    {
        $b = $context->builder;
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $map = $context->structFieldMap['__value__'];
        $payload = $b->load($b->pointerCast($b->structGep($ptr, $map['value']), $context->getTypeFromString('int64*')));
        $isBool = $b->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(Variable::TYPE_NATIVE_BOOL, false));
        $isNull = $b->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(Variable::TYPE_NULL, false));
        $boolVal = $b->zExt($b->icmp(Builder::INT_NE, $b->bitwiseAnd($payload, $i64->constInt(0xff, false)), $i64->constInt(0, false)), $i64);
        $read = $b->call($context->lookupFunction('__value__readLong'), $ptr);

        return $b->select($isNull, $i64->constInt(0, false), $b->select($isBool, $boolVal, $read));
    }

    private static function operatorSymbol(int $opType): string
    {
        return match ($opType) {
            OpCode::TYPE_PLUS => '+',
            OpCode::TYPE_MINUS => '-',
            OpCode::TYPE_MUL => '*',
            OpCode::TYPE_DIV => '/',
            OpCode::TYPE_MODULO => '%',
            OpCode::TYPE_POW => '**',
            default => '?',
        };
    }
}
