<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for atan() via AtanJitHelper PHP (#15142).
 *
 * Replaces libc `atan` LLVM lookup in ext/standard/atan.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmMath}.
 * php-src: ext/standard/math.c — PHP_FUNCTION(atan)
 */
final class MathAtan
{
    private const ABI_ATAN = 'phpc_atan';

    private const HELPER_PATH = '/ext/standard/AtanJitHelper.php';

    private const ATAN_HELPER = 'PHPCompiler\\ext\\standard\\AtanJitHelper::atanArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ATAN_HELPER,
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
            $context->lookupFunction(self::ABI_ATAN),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_ATAN,
            'atan_bridge_entry',
            [$double],
            $double,
            self::ATAN_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15142'
        );
    }
}
