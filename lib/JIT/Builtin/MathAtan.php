<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\ext\standard\JitAtanKernel;
use PHPLLVM\Value;

/**
 * JIT/AOT link for atan() via AtanJitHelper PHP (#15142, #27017).
 *
 * Embed + thin standalone AOT: {@see AtanJitHelper} via {@see JitVmHelperLink}
 * (Ceil/Sqrt #20664 / #27016 asin shape — double via helper result coerce).
 * Nested helper compile: libc leaf without re-entering AtanJitHelper.
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
        if (NestedJitCompileScope::isActive()) {
            return JitAtanKernel::invoke($context, $num);
        }

        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_ATAN),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

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
            '#27017'
        );
    }
}
