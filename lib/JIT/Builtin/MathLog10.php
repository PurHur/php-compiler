<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for log10() via Log10JitHelper PHP (#15101, #27047, #28642).
 *
 * Helper compile: {@see JitVmHelperLink::ensureBridge} (MathLog #28574 / MathLog1p #28495 shape).
 * NestedJIT no longer needs a libc log10(3) kernel — helper uses NestedJIT-safe series.
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

    private const BRIDGE_ENTRY = 'log10_bridge_entry';

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
        $probe = $context->module->getNamedFunction(self::ABI_LOG10);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_LOG10, $probe);

            return;
        }

        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_LOG10,
            self::BRIDGE_ENTRY,
            [$double],
            $double,
            self::LOG10_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#28642'
        );
    }
}
