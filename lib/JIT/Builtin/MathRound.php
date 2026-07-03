<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for round() via RoundJitHelper PHP (#15211).
 *
 * Replaces ~477 LOC inline LLVM in ext/standard/JitRoundLowering.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmRound}.
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
        $double = $context->getTypeFromString('double');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_ROUND,
            'round_bridge_entry',
            [$double, $i64, $i64],
            $double,
            self::ROUND_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15211'
        );
    }
}
