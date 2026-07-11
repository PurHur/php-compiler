<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for is_nan() via IsNanJitHelper PHP (#15173).
 *
 * Replaces libc `isnan` LLVM lookup in ext/standard/is_nan.php.
 * SSOT: {@see \PHPCompiler\ext\standard\IsNanJitHelper}.
 * php-src: ext/standard/math.c — PHP_FUNCTION(is_nan)
 */
final class MathIsNan
{
    private const ABI_IS_NAN = 'phpc_is_nan';

    private const HELPER_PATH = '/ext/standard/IsNanJitHelper.php';

    private const IS_NAN_HELPER = 'PHPCompiler\\ext\\standard\\IsNanJitHelper::isNanArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::IS_NAN_HELPER,
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
            $context->lookupFunction(self::ABI_IS_NAN),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        $double = $context->getTypeFromString('double');
        $i1 = $context->getTypeFromString('int1');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_IS_NAN,
            'is_nan_bridge_entry',
            [$double],
            $i1,
            self::IS_NAN_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15173'
        );
    }
}
