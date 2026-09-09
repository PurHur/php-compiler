<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArithOverflow;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for abs() via {@code llvm.fabs.f64} + inline i64 select (#36386).
 *
 * Peer of {@see MathSqrt}: avoid NestedJIT helper objects on the AOT hot path
 * (php-src {@code ext/standard/math.c} {@code PHP_FUNCTION(abs)} → {@code fabs} /
 * negate-or-identity). The PHP helper remains for VM / NestedJIT only.
 *
 * Typed native-long runtime abs promotes {@code PHP_INT_MIN} → double (same
 * {@code zendi_negate_function} shape as unary −); compile-time longs proven
 * ≠ {@code PHP_INT_MIN} keep the bare select (#36386).
 */
final class MathAbs
{
    private const LLVM_FABS = 'llvm.fabs.f64';

    /** Legacy ABI kept as a thin intrinsic wrapper for any external callers. */
    private const ABI_ABS_DOUBLE = 'phpc_abs_double';

    private const ABI_ABS_LONG = 'phpc_abs_long';

    private const BRIDGE_DOUBLE_ENTRY = 'abs_llvm_fabs_entry';

    private const BRIDGE_LONG_ENTRY = 'abs_i64_select_entry';

    public static function ensureLinked(Context $context): void
    {
        self::llvmFabsIntrinsic($context);
        self::ensurePhpcAbsDoubleBridge($context);
        self::ensurePhpcAbsLongBridge($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invokeDouble(Context $context, Value $num): Value
    {
        return $context->builder->call(self::llvmFabsIntrinsic($context), $num);
    }

    /**
     * Emit {@code x < 0 ? -x : x} at the current insert point (do not redefine
     * the ABI body mid-lowering — that would move the builder).
     *
     * Callers must prove {@code $num ≠ PHP_INT_MIN} (compile-time fold) — LLVM
     * integer negate wraps {@code INT_MIN} to itself (php-src returns float).
     */
    public static function invokeLong(Context $context, Value $num): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $neg = $context->builder->negate($num);
        $isNeg = $context->builder->icmp(
            Builder::INT_SLT,
            $num,
            $i64->constInt(0, true)
        );

        return $context->builder->select($isNeg, $neg, $num);
    }

    /**
     * Runtime typed native-long abs with {@code PHP_INT_MIN} → double (#36386).
     *
     * Hot path stays {@see Variable::TYPE_NATIVE_LONG} (no {@code __value__} box);
     * INT_MIN stores f64 into an entry alloca — materialize boxes for mixed /
     * {@code var_dump} / weak returns. Peer
     * {@see \PHPCompiler\VM\VmUnaryMinus::negateLongWithIntMinPromote}.
     *
     * @see php-src ext/standard/math.c PHP_FUNCTION(abs) IS_LONG / ZEND_LONG_MIN
     */
    public static function invokeLongWithIntMinPromote(Context $context, Value $num): Variable
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'abs_long_int_min_cont');
        $i64 = $context->getTypeFromString('int64');
        $f64 = $context->getTypeFromString('double');
        $long = $context->builder->intCast($num, $i64);

        $isMin = $context->builder->icmp(
            Builder::INT_EQ,
            $long,
            $i64->constInt(\PHP_INT_MIN, true)
        );
        // Spill before split — materialize may load from a non-dominating BB (#36385).
        $flagSlot = JitLongArithOverflow::spillOverflowFlagToEntryAlloca($context, $isMin);
        $doubleSlot = BasicBlockHelper::entryAlloca($context, $f64);

        $minBlock = BasicBlockHelper::append($context, 'abs_long_int_min');
        $okBlock = BasicBlockHelper::append($context, 'abs_long_ok');
        $doneBlock = BasicBlockHelper::append($context, 'abs_long_done');
        $context->builder->branchIf($isMin, $minBlock, $okBlock);

        $context->builder->positionAtEnd($minBlock);
        // fneg(sitofp(INT_MIN)): LLVM integer negate wraps INT_MIN to itself.
        $minAbs = $context->builder->fNegate($context->builder->sitofp($long, $f64));
        $context->builder->store($minAbs, $doubleSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $absLong = self::invokeLong($context, $long);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $mergedLong = $context->builder->phi($i64);
        $mergedLong->addIncoming($absLong, $okBlock);
        $mergedLong->addIncoming($i64->constInt(0, false), $minBlock);

        $okVar = new Variable($context, Variable::TYPE_NATIVE_LONG, Variable::KIND_VALUE, $mergedLong);
        $okVar->longArithOverflowFlag = $flagSlot;
        $okVar->longArithOverflowDoubleSlot = $doubleSlot;

        return $okVar;
    }

    private static function llvmFabsIntrinsic(Context $context): LlvmFunction
    {
        $func = $context->module->getNamedFunction(self::LLVM_FABS);
        if (null !== $func) {
            return $func;
        }
        $double = $context->getTypeFromString('double');

        return $context->module->addFunction(
            self::LLVM_FABS,
            $context->context->functionType($double, false, $double)
        );
    }

    /**
     * Define {@code phpc_abs_double} → {@code llvm.fabs.f64} when missing. Skip if a
     * prior NestedJIT bridge already filled the symbol (cannot replace LLVM
     * bodies); {@see invokeDouble} never calls that stale path.
     */
    private static function ensurePhpcAbsDoubleBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_ABS_DOUBLE);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_ABS_DOUBLE, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $double = $context->getTypeFromString('double');
        $fn = $probe;
        if (null === $fn) {
            $fn = $context->module->addFunction(
                self::ABI_ABS_DOUBLE,
                $context->context->functionType($double, false, $double)
            );
        }
        $entry = $fn->appendBasicBlock(self::BRIDGE_DOUBLE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(
            $context->builder->call(self::llvmFabsIntrinsic($context), $fn->getParam(0))
        );
        $context->registerFunction(self::ABI_ABS_DOUBLE, $fn);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * Optional {@code phpc_abs_long} ABI for external callers (save/restore insert).
     */
    private static function ensurePhpcAbsLongBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_ABS_LONG);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_ABS_LONG, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $i64 = $context->getTypeFromString('int64');
        $fn = $probe;
        if (null === $fn) {
            $fn = $context->module->addFunction(
                self::ABI_ABS_LONG,
                $context->context->functionType($i64, false, $i64)
            );
        }
        $entry = $fn->appendBasicBlock(self::BRIDGE_LONG_ENTRY);
        $context->builder->positionAtEnd($entry);
        $v = $fn->getParam(0);
        $neg = $context->builder->negate($v);
        $isNeg = $context->builder->icmp(
            Builder::INT_SLT,
            $v,
            $i64->constInt(0, true)
        );
        $context->builder->returnValue($context->builder->select($isNeg, $neg, $v));
        $context->registerFunction(self::ABI_ABS_LONG, $fn);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
