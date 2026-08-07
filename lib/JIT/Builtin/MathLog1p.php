<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for log1p() via Log1pJitHelper PHP (#15157, #27057, #28495).
 *
 * Helper compile: {@see JitVmHelperLink::ensureBridge} (MathExpm1 #28487 / MathAtan #28470 shape).
 * NestedJIT no longer needs a libc log1p(3) kernel — helper uses NestedJIT-safe series/log.
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

    private const BRIDGE_ENTRY = 'log1p_bridge_entry';

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
        $probe = $context->module->getNamedFunction(self::ABI_LOG1P);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_LOG1P, $probe);

            return;
        }

        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_LOG1P,
            self::BRIDGE_ENTRY,
            [$double],
            $double,
            self::LOG1P_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#28495'
        );
    }
}
