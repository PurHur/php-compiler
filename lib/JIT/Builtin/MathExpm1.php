<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for expm1() via Expm1JitHelper PHP (#15157, #27057, #28487).
 *
 * Helper compile: {@see JitVmHelperLink::ensureBridge} (MathExp #28241 / MathAtan #28470 shape).
 * NestedJIT no longer needs a libc expm1(3) kernel — helper uses NestedJIT-safe Taylor/reduction.
 * php-src: ext/standard/math.c — PHP_FUNCTION(expm1)
 */
final class MathExpm1
{
    private const ABI_EXPM1 = 'phpc_expm1';

    private const HELPER_PATH = '/ext/standard/Expm1JitHelper.php';

    private const EXPM1_HELPER = 'PHPCompiler\\ext\\standard\\Expm1JitHelper::expm1Argv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::EXPM1_HELPER,
    ];

    private const BRIDGE_ENTRY = 'expm1_bridge_entry';

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
            $context->lookupFunction(self::ABI_EXPM1),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_EXPM1);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_EXPM1, $probe);

            return;
        }

        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_EXPM1,
            self::BRIDGE_ENTRY,
            [$double],
            $double,
            self::EXPM1_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#28487'
        );
    }
}
