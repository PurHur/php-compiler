<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for is_finite() via IsFiniteJitHelper PHP (#15188).
 *
 * Replaces libc isnan/isinf compose in lib/JIT/JitIsFinite.php.
 * SSOT: {@see \PHPCompiler\ext\standard\IsFiniteJitHelper}.
 * php-src: ext/standard/math.c — PHP_FUNCTION(is_finite)
 */
final class MathIsFinite
{
    private const ABI_IS_FINITE = 'phpc_is_finite';

    private const HELPER_PATH = '/ext/standard/IsFiniteJitHelper.php';

    private const IS_FINITE_HELPER = 'PHPCompiler\\ext\\standard\\IsFiniteJitHelper::isFiniteArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::IS_FINITE_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $num): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_IS_FINITE),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        $double = $context->getTypeFromString('double');
        $i1 = $context->getTypeFromString('int1');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_IS_FINITE,
            'is_finite_bridge_entry',
            [$double],
            $i1,
            self::IS_FINITE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15188'
        );
    }
}
