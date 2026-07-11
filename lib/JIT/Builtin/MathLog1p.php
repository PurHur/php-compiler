<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for log1p() via Log1pJitHelper PHP (#15157).
 *
 * Replaces libc `log1p` LLVM lookup in ext/standard/log1p.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmMath}.
 * php-src: ext/standard/math.c — PHP_FUNCTION(log1p)
 */
final class MathLog1p
{
    private const ABI_LOG1P = 'phpc_log1p';

    private const HELPER_PATH = '/ext/standard/Log1pJitHelper.php';

    private const LOG1P_HELPER = 'PHPCompiler\\ext\\standard\\Log1pJitHelper::log1pArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::LOG1P_HELPER,
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
            $context->lookupFunction(self::ABI_LOG1P),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_LOG1P,
            'log1p_bridge_entry',
            [$double],
            $double,
            self::LOG1P_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15157'
        );
    }
}
