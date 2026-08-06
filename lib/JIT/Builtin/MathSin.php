<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for sin() via SinJitHelper PHP (#15086, #27048, #28016).
 *
 * Helper compile: {@see JitVmHelperLink::ensureBridge} (MathHypot #27909 / MathSqrt #27888 shape).
 * NestedJIT no longer needs a libc sin(3) kernel — helper uses NestedJIT-safe Cody–Waite + polynomial.
 * php-src: ext/standard/math.c — PHP_FUNCTION(sin)
 */
final class MathSin
{
    private const ABI_SIN = 'phpc_sin';

    private const HELPER_PATH = '/ext/standard/SinJitHelper.php';

    private const SIN_HELPER = 'PHPCompiler\\ext\\standard\\SinJitHelper::sinArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::SIN_HELPER,
    ];

    private const BRIDGE_ENTRY = 'sin_bridge_entry';

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
            $context->lookupFunction(self::ABI_SIN),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_SIN);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_SIN, $probe);

            return;
        }

        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_SIN,
            self::BRIDGE_ENTRY,
            [$double],
            $double,
            self::SIN_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#28016'
        );
    }
}
