<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for round() via RoundJitHelper PHP (#15211, #26800, #28913).
 *
 * Helper compile: {@see JitVmHelperLink::ensureBridge} (MathFloor #27650 / MathNextafter #28716 shape).
 * NestedJIT no longer skips the bridge — RoundJitHelper is NestedJIT-safe (no \floor/\ceil/\abs).
 * Replaces ~477 LOC inline LLVM formerly in ext/standard/JitRoundLowering.php.
 * SSOT: {@see \PHPCompiler\ext\standard\RoundJitHelper::roundArgv}.
 * php-src: ext/standard/math.c — _php_math_round
 */
final class MathRound
{
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
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
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

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_ROUND);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_ROUND, $probe);

            return;
        }

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
    }
}
