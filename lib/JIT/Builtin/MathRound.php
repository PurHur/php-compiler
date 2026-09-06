<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for round() (#15211, #26800, #28913, #36386).
 *
 * places=0 modes use LLVM f64 ops (no NestedJIT helper):
 * - {@code PHP_ROUND_HALF_UP} → {@code llvm.round.f64} (C {@code round(3)})
 * - {@code PHP_ROUND_HALF_DOWN} → trunc + bump when |frac| > 0.5
 * - {@code PHP_ROUND_HALF_EVEN} / {@code HALF_ODD} → trunc + tie-to-even/odd
 * - {@code PHP_ROUND_CEILING} → {@code llvm.ceil.f64} ({@see MathCeil})
 * - {@code PHP_ROUND_FLOOR} → {@code llvm.floor.f64} ({@see MathFloor})
 * - {@code PHP_ROUND_TOWARD_ZERO} → {@code llvm.trunc.f64}
 * - {@code PHP_ROUND_AWAY_FROM_ZERO} → {@code copysign(ceil(|x|), x)}
 *
 * Matching php-src {@code ext/standard/math.c} {@code _php_math_round} /
 * {@code php_math_round_mode.h} for those modes.
 *
 * Non-zero places with a compile-time directed mode scale via
 * {@see \PHPCompiler\ext\standard\JitRound} (no NestedJIT). Runtime-unknown
 * places still use the NestedJIT {@see \PHPCompiler\ext\standard\RoundJitHelper}
 * bridge. SSOT: {@see \PHPCompiler\ext\standard\RoundJitHelper::roundArgv}.
 */
final class MathRound
{
    private const LLVM_ROUND = 'llvm.round.f64';

    private const LLVM_TRUNC = 'llvm.trunc.f64';

    private const ABI_ROUND = 'phpc_round';

    private const HELPER_PATH = '/ext/standard/RoundJitHelper.php';

    private const ROUND_HELPER = 'PHPCompiler\\ext\\standard\\RoundJitHelper::roundArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ROUND_HELPER,
    ];

    private const BRIDGE_ENTRY = 'round_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::llvmRoundIntrinsic($context);
        self::llvmTruncIntrinsic($context);
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    /**
     * places=0 + PHP_ROUND_HALF_UP — {@code llvm.round.f64} (no NestedJIT helper).
     */
    public static function invokeHalfUpPlacesZero(Context $context, Value $num): Value
    {
        return $context->builder->call(self::llvmRoundIntrinsic($context), $num);
    }

    /**
     * places=0 + PHP_ROUND_HALF_DOWN — round halves toward zero (#36386).
     *
     * php-src {@code _php_math_round} mode 2: bump only when |frac| > 0.5.
     */
    public static function invokeHalfDownPlacesZero(Context $context, Value $num): Value
    {
        return self::invokeHalfAwayWhenStrictGtHalf($context, $num, false, false);
    }

    /**
     * places=0 + PHP_ROUND_HALF_EVEN — banker's rounding (#36386).
     *
     * php-src mode 3: bump on |frac| > 0.5, or on exact .5 when trunc is odd.
     */
    public static function invokeHalfEvenPlacesZero(Context $context, Value $num): Value
    {
        return self::invokeHalfAwayWhenStrictGtHalf($context, $num, true, false);
    }

    /**
     * places=0 + PHP_ROUND_HALF_ODD — opposite of banker's (#36386).
     *
     * php-src mode 4: bump on |frac| > 0.5, or on exact .5 when trunc is even.
     */
    public static function invokeHalfOddPlacesZero(Context $context, Value $num): Value
    {
        return self::invokeHalfAwayWhenStrictGtHalf($context, $num, true, true);
    }

    /**
     * places=0 + PHP_ROUND_CEILING — {@code llvm.ceil.f64} (toward +∞).
     */
    public static function invokeCeilingPlacesZero(Context $context, Value $num): Value
    {
        return MathCeil::invoke($context, $num);
    }

    /**
     * places=0 + PHP_ROUND_FLOOR — {@code llvm.floor.f64} (toward −∞).
     */
    public static function invokeFloorPlacesZero(Context $context, Value $num): Value
    {
        return MathFloor::invoke($context, $num);
    }

    /**
     * places=0 + PHP_ROUND_TOWARD_ZERO — {@code llvm.trunc.f64}.
     */
    public static function invokeTowardZeroPlacesZero(Context $context, Value $num): Value
    {
        return $context->builder->call(self::llvmTruncIntrinsic($context), $num);
    }

    /**
     * places=0 + PHP_ROUND_AWAY_FROM_ZERO — {@code copysign(ceil(|x|), x)} (#36386).
     *
     * php-src mode 8 / {@code php_math_round_mode.h}: any non-zero fractional
     * part moves away from zero (integers unchanged).
     */
    public static function invokeAwayFromZeroPlacesZero(Context $context, Value $num): Value
    {
        $double = $context->getTypeFromString('double');
        $zero = $double->constReal(0.0);
        $abs = MathAbs::invokeDouble($context, $num);
        $ceiled = MathCeil::invoke($context, $abs);
        $neg = $context->builder->fsub($zero, $ceiled);
        $isNeg = $context->builder->fcmp(Builder::REAL_OLT, $num, $zero);

        return $context->builder->select($isNeg, $neg, $ceiled);
    }

    /**
     * Shared HALF_DOWN / HALF_EVEN / HALF_ODD places=0 lowering.
     *
     * @param bool $tieBreak  when true, exact .5 uses parity of trunc
     * @param bool $tieOdd    when tieBreak: bump if trunc is even (HALF_ODD); else if odd (HALF_EVEN)
     */
    private static function invokeHalfAwayWhenStrictGtHalf(
        Context $context,
        Value $num,
        bool $tieBreak,
        bool $tieOdd
    ): Value {
        $double = $context->getTypeFromString('double');
        $i64 = $context->getTypeFromString('int64');
        $zero = $double->constReal(0.0);
        $one = $double->constReal(1.0);
        $negOne = $double->constReal(-1.0);
        $half = $double->constReal(0.5);

        $truncRaw = self::invokeTowardZeroPlacesZero($context, $num);
        // Match php-src / RoundJitHelper: (float)(int)$num normalizes −0 from
        // trunc(−0.5) to +0 so echo matches Zend (#36386).
        $asInt = $context->builder->fptosi($truncRaw, $i64);
        $trunc = $context->builder->sitofp($asInt, $double);
        $diff = $context->builder->fsub($num, $trunc);
        $absDiff = MathAbs::invokeDouble($context, $diff);
        $gtHalf = $context->builder->fcmp(Builder::REAL_OGT, $absDiff, $half);
        $isNeg = $context->builder->fcmp(Builder::REAL_OLT, $num, $zero);
        $step = $context->builder->select($isNeg, $negOne, $one);
        $bumped = $context->builder->fadd($trunc, $step);

        $shouldBump = $gtHalf;
        if ($tieBreak) {
            $eqHalf = $context->builder->fcmp(Builder::REAL_OEQ, $absDiff, $half);
            $lsb = $context->builder->bitwiseAnd($asInt, $i64->constInt(1, false));
            $isOdd = $context->builder->icmp(
                Builder::INT_NE,
                $lsb,
                $i64->constInt(0, false)
            );
            $tieParity = $tieOdd
                ? $context->builder->not($context->castToBool($isOdd))
                : $context->castToBool($isOdd);
            $tieBump = $context->builder->bitwiseAnd(
                $context->castToBool($eqHalf),
                $tieParity
            );
            $shouldBump = $context->builder->bitwiseOr(
                $context->castToBool($gtHalf),
                $tieBump
            );
        }

        return $context->builder->select($shouldBump, $bumped, $trunc);
    }

    public static function invoke(Context $context, Value $num, Value $places, Value $mode): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_ROUND),
            $num,
            $places,
            $mode
        );
    }

    private static function llvmRoundIntrinsic(Context $context): LlvmFunction
    {
        $func = $context->module->getNamedFunction(self::LLVM_ROUND);
        if (null !== $func) {
            return $func;
        }
        $double = $context->getTypeFromString('double');

        return $context->module->addFunction(
            self::LLVM_ROUND,
            $context->context->functionType($double, false, $double)
        );
    }

    private static function llvmTruncIntrinsic(Context $context): LlvmFunction
    {
        $func = $context->module->getNamedFunction(self::LLVM_TRUNC);
        if (null !== $func) {
            return $func;
        }
        $double = $context->getTypeFromString('double');

        return $context->module->addFunction(
            self::LLVM_TRUNC,
            $context->context->functionType($double, false, $double)
        );
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_ROUND);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_ROUND, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $double = $context->getTypeFromString('double');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_ROUND,
            self::BRIDGE_ENTRY,
            [$double, $i64, $i64],
            $double,
            self::ROUND_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#28913'
        );
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
