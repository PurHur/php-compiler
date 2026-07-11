<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for deg2rad() via Deg2radJitHelper PHP (#15143).
 *
 * Replaces inline LLVM fMul constant lowering in ext/standard/deg2rad.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmMath}.
 * php-src: ext/standard/math.c — PHP_FUNCTION(deg2rad)
 */
final class MathDeg2rad
{
    private const ABI_DEG2RAD = 'phpc_deg2rad';

    private const HELPER_PATH = '/ext/standard/Deg2radJitHelper.php';

    private const DEG2RAD_HELPER = 'PHPCompiler\\ext\\standard\\Deg2radJitHelper::deg2radArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::DEG2RAD_HELPER,
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
            $context->lookupFunction(self::ABI_DEG2RAD),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_DEG2RAD,
            'deg2rad_bridge_entry',
            [$double],
            $double,
            self::DEG2RAD_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15143'
        );
    }
}
