<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for log10() via Log10JitHelper PHP (#15101).
 *
 * Replaces libc `log10` LLVM lookup in ext/standard/log10.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmMath}.
 * php-src: ext/standard/math.c — PHP_FUNCTION(log10)
 */
final class MathLog10
{
    private const ABI_LOG10 = 'phpc_log10';

    private const HELPER_PATH = '/ext/standard/Log10JitHelper.php';

    private const LOG10_HELPER = 'PHPCompiler\\ext\\standard\\Log10JitHelper::log10Argv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::LOG10_HELPER,
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
            $context->lookupFunction(self::ABI_LOG10),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_LOG10,
            'log10_bridge_entry',
            [$double],
            $double,
            self::LOG10_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15101'
        );
    }
}
