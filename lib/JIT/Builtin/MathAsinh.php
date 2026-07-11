<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for asinh() via AsinhJitHelper PHP (#15221).
 *
 * Replaces libc `asinh` LLVM lookup in ext/standard/asinh.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmMath}.
 * php-src: ext/standard/math.c — PHP_FUNCTION(asinh)
 */
final class MathAsinh
{
    private const ABI_ASINH = 'phpc_asinh';

    private const HELPER_PATH = '/ext/standard/AsinhJitHelper.php';

    private const ASINH_HELPER = 'PHPCompiler\\ext\\standard\\AsinhJitHelper::asinhArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ASINH_HELPER,
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
            $context->lookupFunction(self::ABI_ASINH),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_ASINH,
            'asinh_bridge_entry',
            [$double],
            $double,
            self::ASINH_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15221'
        );
    }
}
