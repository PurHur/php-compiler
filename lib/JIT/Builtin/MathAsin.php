<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\ext\standard\JitAsinKernel;
use PHPLLVM\Value;

/**
 * JIT/AOT link for asin() via AsinJitHelper PHP (#15130, #27016).
 *
 * Embed + thin standalone AOT: {@see AsinJitHelper} via {@see JitVmHelperLink}
 * (Ceil/Sqrt #20664 / #27048 acos shape — double via helper result coerce).
 * Nested helper compile: libc leaf without re-entering AsinJitHelper.
 * php-src: ext/standard/math.c — PHP_FUNCTION(asin)
 */
final class MathAsin
{
    private const ABI_ASIN = 'phpc_asin';

    private const HELPER_PATH = '/ext/standard/AsinJitHelper.php';

    private const ASIN_HELPER = 'PHPCompiler\\ext\\standard\\AsinJitHelper::asinArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ASIN_HELPER,
    ];

    private const BRIDGE_ENTRY = 'asin_bridge_entry';

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
        if (NestedJitCompileScope::isActive()) {
            return JitAsinKernel::invoke($context, $num);
        }

        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_ASIN),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_ASIN);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_ASIN, $probe);

            return;
        }

        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_ASIN,
            self::BRIDGE_ENTRY,
            [$double],
            $double,
            self::ASIN_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#27016'
        );
    }
}
