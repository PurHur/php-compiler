<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\ext\standard\JitLog10Kernel;
use PHPLLVM\Value;

/**
 * JIT/AOT link for log10() via Log10JitHelper PHP (#15101, #27047).
 *
 * Embed + thin standalone AOT: {@see Log10JitHelper} via {@see JitVmHelperLink}
 * (Ceil/Sqrt #20664 / #27003 shape — double via helper result coerce).
 * Nested helper compile: libc leaf without re-entering Log10JitHelper.
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
        if (NestedJitCompileScope::isActive()) {
            return JitLog10Kernel::invoke($context, $num);
        }

        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_LOG10),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

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
            '#27047'
        );
    }
}
