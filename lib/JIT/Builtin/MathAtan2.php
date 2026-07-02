<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for atan2() via Atan2JitHelper PHP (#15102).
 *
 * Replaces libc `atan2` LLVM lookup in ext/standard/atan2.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmMath}.
 * php-src: ext/standard/math.c — PHP_FUNCTION(atan2)
 */
final class MathAtan2
{
    private const ABI_ATAN2 = 'phpc_atan2';

    private const HELPER_PATH = '/ext/standard/Atan2JitHelper.php';

    private const ATAN2_HELPER = 'PHPCompiler\\ext\\standard\\Atan2JitHelper::atan2Argv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ATAN2_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $y, Value $x): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_ATAN2),
            $y,
            $x
        );
    }

    private static function implement(Context $context): void
    {
        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_ATAN2,
            'atan2_bridge_entry',
            [$double, $double],
            $double,
            self::ATAN2_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15102'
        );
    }
}
