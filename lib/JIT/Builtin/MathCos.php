<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for cos() via CosJitHelper PHP (#15087, #27005, #28042).
 *
 * Helper compile: {@see JitVmHelperLink::ensureBridge} (MathSin #28016 / MathHypot #27909 shape).
 * NestedJIT no longer needs a libc cos(3) kernel — helper uses NestedJIT-safe even Taylor.
 * php-src: ext/standard/math.c — PHP_FUNCTION(cos)
 */
final class MathCos
{
    private const ABI_COS = 'phpc_cos';

    private const HELPER_PATH = '/ext/standard/CosJitHelper.php';

    private const COS_HELPER = 'PHPCompiler\\ext\\standard\\CosJitHelper::cosArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::COS_HELPER,
    ];

    private const BRIDGE_ENTRY = 'cos_bridge_entry';

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
            $context->lookupFunction(self::ABI_COS),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_COS);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_COS, $probe);

            return;
        }

        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_COS,
            self::BRIDGE_ENTRY,
            [$double],
            $double,
            self::COS_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#28042'
        );
    }
}
