<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for atanh() via AtanhJitHelper PHP (#15221).
 *
 * Replaces libc `atanh` LLVM lookup in ext/standard/atanh.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmMath}.
 * php-src: ext/standard/math.c — PHP_FUNCTION(atanh)
 */
final class MathAtanh
{
    private const ABI_ATANH = 'phpc_atanh';

    private const HELPER_PATH = '/ext/standard/AtanhJitHelper.php';

    private const ATANH_HELPER = 'PHPCompiler\\ext\\standard\\AtanhJitHelper::atanhArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ATANH_HELPER,
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
            $context->lookupFunction(self::ABI_ATANH),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_ATANH,
            'atanh_bridge_entry',
            [$double],
            $double,
            self::ATANH_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15221'
        );
    }
}
