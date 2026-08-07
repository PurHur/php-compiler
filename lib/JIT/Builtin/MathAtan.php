<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for atan() via AtanJitHelper PHP (#15142, #27017, #28470).
 *
 * Helper compile: {@see JitVmHelperLink::ensureBridge} (MathAsin #28263 / MathTanh #28459 shape).
 * NestedJIT no longer needs a libc atan(3) kernel — helper uses NestedJIT-safe fdlibm poly.
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

    private const BRIDGE_ENTRY = 'atan_bridge_entry';

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
        $probe = $context->module->getNamedFunction(self::ABI_ATAN);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_ATAN, $probe);

            return;
        }

        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_ATAN,
            self::BRIDGE_ENTRY,
            [$double],
            $double,
            self::ATAN_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#28470'
        );
    }
}
