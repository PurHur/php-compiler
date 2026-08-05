<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\ext\standard\JitAtan2Kernel;
use PHPLLVM\Value;

/**
 * JIT/AOT link for atan2() via Atan2JitHelper PHP (#15102, #27017).
 *
 * Embed + thin standalone AOT: {@see Atan2JitHelper} via {@see JitVmHelperLink}
 * (Ceil/Sqrt #20664 / #27016 asin shape — double via helper result coerce).
 * Nested helper compile: libc leaf without re-entering Atan2JitHelper.
 * php-src: ext/standard/math.c — PHP_FUNCTION(atan2)
 */
final class MathAtan2
{
    private const ABI_ATAN2 = 'phpc_atan2';

    private const HELPER_PATH = '/ext/standard/Atan2JitHelper.php';

    private const ATAN2_HELPER = 'PHPCompiler\\ext\\standard\\Atan2JitHelper::atan2Argv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ATAN2_HELPER,
    ];

    private const BRIDGE_ENTRY = 'atan2_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $y, Value $x): Value
    {
        if (NestedJitCompileScope::isActive()) {
            return JitAtan2Kernel::invoke($context, $y, $x);
        }

        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_ATAN2),
            $y,
            $x
        );
    }

    private static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_ATAN2);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_ATAN2, $probe);

            return;
        }

        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_ATAN2,
            self::BRIDGE_ENTRY,
            [$double, $double],
            $double,
            self::ATAN2_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#27017'
        );
    }
}
