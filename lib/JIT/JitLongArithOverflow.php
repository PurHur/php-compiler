<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\OpCode;
use PHPLLVM\Value as LlvmValue;

/**
 * JIT long + / * / − with PHP_INT overflow → double promotion (#31964 / #32422 / #32426).
 *
 * @see php-src Zend/zend_operators.h ZEND_SIGNED_ADD_OVERFLOW /
 *      ZEND_LONG_MUL_OVERFLOW / fast_long_sub_function /
 *      add_function / mul_function / sub_function
 */
final class JitLongArithOverflow
{
    public static function supportsOpcode(int $opType): bool
    {
        return OpCode::TYPE_PLUS === $opType
            || OpCode::TYPE_MUL === $opType
            || OpCode::TYPE_MINUS === $opType;
    }

    /**
     * Compile-time fold: PHP native arithmetic already promotes on overflow.
     */
    public static function tryFoldBinary(
        Context $context,
        int $opType,
        Variable $left,
        Variable $right
    ): ?Variable {
        if (!self::supportsOpcode($opType)) {
            return null;
        }
        $a = self::extractConstantLong($context, $left);
        $b = self::extractConstantLong($context, $right);
        if (null === $a || null === $b) {
            return null;
        }
        $result = self::foldNativeInts($opType, $a, $b);
        if (\is_int($result)) {
            return new Variable(
                $context,
                Variable::TYPE_NATIVE_LONG,
                Variable::KIND_VALUE,
                $context->constantFromInteger($result, 'int64')
            );
        }

        return new Variable(
            $context,
            Variable::TYPE_NATIVE_DOUBLE,
            Variable::KIND_VALUE,
            $context->constantFromFloat((float) $result, 'double')
        );
    }

    /**
     * Native long ⊙ native long with overflow → double (#31964).
     *
     * Hot path stays {@see Variable::TYPE_NATIVE_LONG} (no {@code __value__} box) per #36189;
     * overflow stores a cold f64 into an entry alloca — the {@code __value__} box is created
     * only if {@see materializeOverflowableNativeLong} runs (#36386). Typed recursion like
     * {@code fibo_r} never materializes, so it no longer pays three entry value-box inits
     * per {@code +}/{@code -} site.
     *
     * Uses {@code llvm.s{add,sub,mul}.with.overflow.i64} (php-src
     * {@code ZEND_SIGNED_*_OVERFLOW} / {@code __builtin_*_overflow} shape) so the
     * hot path is one intrinsic + extract instead of a multi-icmp dance (#36386).
     */
    public static function binaryNativeLong(
        Context $context,
        int $opType,
        LlvmValue $left,
        LlvmValue $right
    ): Variable {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'native_long_bin_cont');
        $i64 = $context->getTypeFromString('int64');
        $f64 = $context->getTypeFromString('double');
        $a = $context->builder->intCast($left, $i64);
        $b = $context->builder->intCast($right, $i64);

        [$lres, $overflow] = self::emitWithOverflow($context, $opType, $a, $b);

        // f64 only — no entryAllocaValueBox / TYPE_NULL init on the hot path (#36386).
        $doubleSlot = BasicBlockHelper::entryAlloca($context, $f64);

        $ovBlock = BasicBlockHelper::append($context, 'native_long_bin_ov');
        $okBlock = BasicBlockHelper::append($context, 'native_long_bin_ok');
        $doneBlock = BasicBlockHelper::append($context, 'native_long_bin_done');
        $context->builder->branchIf($overflow, $ovBlock, $okBlock);

        $context->builder->positionAtEnd($ovBlock);
        $ad = $context->builder->siToFp($a, $f64);
        $bd = $context->builder->siToFp($b, $f64);
        $fd = self::emitFloatOp($context, $opType, $ad, $bd);
        $context->builder->store($fd, $doubleSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        // Dummy 0 on the overflow arm — consumers must materialize when the flag is set.
        $mergedLong = $context->builder->phi($i64);
        $mergedLong->addIncoming($lres, $okBlock);
        $mergedLong->addIncoming($i64->constInt(0, false), $ovBlock);

        $okVar = new Variable($context, Variable::TYPE_NATIVE_LONG, Variable::KIND_VALUE, $mergedLong);
        $okVar->longArithOverflowFlag = $overflow;
        $okVar->longArithOverflowDoubleSlot = $doubleSlot;

        return $okVar;
    }

    /**
     * When a native-long arith result may have overflowed to double, lower to a
     * single {@see Variable::TYPE_VALUE} for consumers that cannot stay native.
     */
    public static function materializeOverflowableNativeLong(Context $context, Variable $var): Variable
    {
        if (null === $var->longArithOverflowFlag) {
            return $var;
        }
        if (null === $var->longArithOverflowDoubleSlot && null === $var->longArithOverflowPromoted) {
            return $var;
        }
        if (Variable::TYPE_VALUE === $var->type) {
            return $var;
        }

        // Flag is the i1 SSA from llvm.*.with.overflow (or a legacy i1* alloca).
        $flag = $var->longArithOverflowFlag;
        $isOv = \PHPLLVM\Type::KIND_POINTER === $flag->typeOf()->getKind()
            ? $context->builder->load($flag)
            : $flag;

        $longBb = BasicBlockHelper::append($context, 'long_arith_mat_long');
        $ovBb = BasicBlockHelper::append($context, 'long_arith_mat_ov');
        $outBb = BasicBlockHelper::append($context, 'long_arith_mat_out');
        $context->builder->branchIf($isOv, $ovBb, $longBb);

        $context->builder->positionAtEnd($longBb);
        $slot = JitValueBox::alloc($context);
        $slotPtr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeLong($context, $slot, $var->value);
        JitValueBox::publishAfterWrite($context, $slotPtr);
        $context->builder->branch($outBb);

        $context->builder->positionAtEnd($ovBb);
        if (null !== $var->longArithOverflowDoubleSlot) {
            $fd = $context->builder->load($var->longArithOverflowDoubleSlot);
            $ovSlot = JitValueBox::alloc($context);
            $ovPtr = JitValueBox::pointer($context, $ovSlot);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $ovPtr,
                $fd
            );
            JitValueBox::publishAfterWrite($context, $ovPtr);
        } else {
            $legacy = $var->longArithOverflowPromoted;
            if (null === $legacy) {
                throw new \LogicException('native long overflow materialize missing double slot and legacy box');
            }
            $ovPtr = JitValueBox::normalizeValuePtr($context, $legacy->value);
        }
        $context->builder->branch($outBb);

        $context->builder->positionAtEnd($outBb);
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $outPhi = $context->builder->phi($valuePtrTy);
        $outPhi->addIncoming($slotPtr, $longBb);
        $outPhi->addIncoming($ovPtr, $ovBb);

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VALUE, $outPhi);
    }

    /**
     * Boxed long ⊙ boxed long — write long or double into an existing value slot.
     */
    public static function writeBoxedBinary(
        Context $context,
        int $opType,
        LlvmValue $left,
        LlvmValue $right,
        LlvmValue $slotPtr
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'vbox_arith_overflow_cont');
        $i64 = $context->getTypeFromString('int64');
        $f64 = $context->getTypeFromString('double');
        $a = $context->builder->intCast($left, $i64);
        $b = $context->builder->intCast($right, $i64);

        [$lres, $overflow] = self::emitWithOverflow($context, $opType, $a, $b);

        $ovBlock = BasicBlockHelper::append($context, 'vbox_arith_overflow');
        $okBlock = BasicBlockHelper::append($context, 'vbox_arith_ok');
        $doneBlock = BasicBlockHelper::append($context, 'vbox_arith_done');
        $context->builder->branchIf($overflow, $ovBlock, $okBlock);

        $context->builder->positionAtEnd($ovBlock);
        $ad = $context->builder->siToFp($a, $f64);
        $bd = $context->builder->siToFp($b, $f64);
        $fd = self::emitFloatOp($context, $opType, $ad, $bd);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $slotPtr,
            $fd
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $slotPtr,
            $lres
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        JitValueBox::publishAfterWrite($context, $slotPtr);
    }

    /**
     * @return array{0: LlvmValue, 1: LlvmValue} [result i64, overflow i1]
     */
    private static function emitWithOverflow(
        Context $context,
        int $opType,
        LlvmValue $a,
        LlvmValue $b
    ): array {
        if (OpCode::TYPE_PLUS === $opType) {
            return self::intrinsicWithOverflow($context, 'llvm.sadd.with.overflow.i64', $a, $b);
        }
        if (OpCode::TYPE_MUL === $opType) {
            return self::intrinsicWithOverflow($context, 'llvm.smul.with.overflow.i64', $a, $b);
        }

        return self::intrinsicWithOverflow($context, 'llvm.ssub.with.overflow.i64', $a, $b);
    }

    /**
     * @return array{0: LlvmValue, 1: LlvmValue} [result i64, overflow i1]
     */
    private static function intrinsicWithOverflow(
        Context $context,
        string $name,
        LlvmValue $a,
        LlvmValue $b
    ): array {
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $aggTy = $context->context->structType(true, $i64, $i1);
        $func = $context->module->getNamedFunction($name);
        if (null === $func) {
            $func = $context->module->addFunction(
                $name,
                $context->context->functionType($aggTy, false, $i64, $i64)
            );
        }
        $pair = $context->builder->call($func, $a, $b);

        return [
            $context->builder->extractValue($pair, 0),
            $context->builder->extractValue($pair, 1),
        ];
    }

    private static function emitFloatOp(
        Context $context,
        int $opType,
        LlvmValue $a,
        LlvmValue $b
    ): LlvmValue {
        if (OpCode::TYPE_PLUS === $opType) {
            return $context->builder->fadd($a, $b);
        }
        if (OpCode::TYPE_MUL === $opType) {
            return $context->builder->fmul($a, $b);
        }

        return $context->builder->fsub($a, $b);
    }

    /** @return int|float */
    private static function foldNativeInts(int $opType, int $a, int $b)
    {
        if (OpCode::TYPE_PLUS === $opType) {
            return $a + $b;
        }
        if (OpCode::TYPE_MUL === $opType) {
            return $a * $b;
        }

        return $a - $b;
    }

    private static function extractConstantLong(Context $context, Variable $var): ?int
    {
        // KIND_VARIABLE (alloca-backed) locals are mutable at runtime; their
        // compileTimeLong is set at the first assignment and becomes stale
        // inside loops — folding $t+1 as 0+1 on every iteration (#32605).
        if (Variable::KIND_VARIABLE === $var->kind) {
            return null;
        }
        // TYPE_VALUE (boxed) variables backed by a heap __value__ box are
        // also mutable at runtime; compileTimeLong is stale in loops (#32605).
        if (JitValueBox::isValueOperand($var)) {
            return null;
        }
        // ConstFetch of PHP_INT_MIN/MAX is a load of a module global with
        // compileTimeLong — not KIND_CONSTANT_INT (#32309).
        if (null !== $var->compileTimeLong) {
            return $var->compileTimeLong;
        }
        if (Variable::KIND_VALUE !== $var->kind
            || null === $var->value
            || LlvmValue::KIND_CONSTANT_INT !== $var->value->getKind()
        ) {
            return null;
        }

        return (int) $context->llvm->lib->LLVMConstIntGetSExtValue($var->value->value);
    }
}
