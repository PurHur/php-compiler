<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for rad2deg() via Rad2degJitHelper PHP (#15143).
 *
 * Replaces inline LLVM fMul constant lowering in ext/standard/rad2deg.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmMath}.
 * php-src: ext/standard/math.c — PHP_FUNCTION(rad2deg)
 */
final class MathRad2deg
{
    private const ABI_RAD2DEG = 'phpc_rad2deg';

    private const HELPER_PATH = '/ext/standard/Rad2degJitHelper.php';

    private const RAD2DEG_HELPER = 'PHPCompiler\\ext\\standard\\Rad2degJitHelper::rad2degArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::RAD2DEG_HELPER,
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
            $context->lookupFunction(self::ABI_RAD2DEG),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_RAD2DEG,
            'rad2deg_bridge_entry',
            [$double],
            $double,
            self::RAD2DEG_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15143'
        );
    }
}
