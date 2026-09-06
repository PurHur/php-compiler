<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for round() (#15211, #26800, #28913, #36386).
 *
 * places=0 + directed modes use LLVM f64 intrinsics (no NestedJIT helper):
 * - {@code PHP_ROUND_HALF_UP} → {@code llvm.round.f64} (C {@code round(3)})
 * - {@code PHP_ROUND_CEILING} → {@code llvm.ceil.f64} ({@see MathCeil})
 * - {@code PHP_ROUND_FLOOR} → {@code llvm.floor.f64} ({@see MathFloor})
 * - {@code PHP_ROUND_TOWARD_ZERO} → {@code llvm.trunc.f64}
 *
 * Matching php-src {@code ext/standard/math.c} {@code _php_math_round} /
 * {@code php_math_round_mode.h} for those modes.
 *
 * Non-zero places / HALF_DOWN / HALF_EVEN / HALF_ODD / AWAY_FROM_ZERO keep the
 * NestedJIT {@see \PHPCompiler\ext\standard\RoundJitHelper} bridge.
 * SSOT for those paths: {@see \PHPCompiler\ext\standard\RoundJitHelper::roundArgv}.
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
