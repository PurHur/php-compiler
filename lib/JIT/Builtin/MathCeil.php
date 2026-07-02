<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for ceil() via CeilJitHelper PHP (#15129).
 *
 * Replaces libc `ceil` LLVM lookup in ext/standard/ceil.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmMath}.
 * php-src: ext/standard/math.c — PHP_FUNCTION(ceil)
 */
final class MathCeil
{
    private const ABI_CEIL = 'phpc_ceil';

    private const HELPER_PATH = '/ext/standard/CeilJitHelper.php';

    private const CEIL_HELPER = 'PHPCompiler\\ext\\standard\\CeilJitHelper::ceilArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::CEIL_HELPER,
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
            $context->lookupFunction(self::ABI_CEIL),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_CEIL,
            'ceil_bridge_entry',
            [$double],
            $double,
            self::CEIL_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15129'
        );
    }
}
