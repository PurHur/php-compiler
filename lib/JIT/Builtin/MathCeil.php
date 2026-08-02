<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\ext\standard\JitCeilKernel;
use PHPLLVM\Value;

/**
 * JIT/AOT link for ceil() via CeilJitHelper PHP (#15129, #27003).
 *
 * Embed + thin standalone AOT: {@see CeilJitHelper} via {@see JitVmHelperLink}
 * (Hypot/Sqrt #20664 / Fmod #26994 shape — double via helper result coerce).
 * Nested helper compile: libc leaf without re-entering CeilJitHelper.
 * php-src: ext/standard/math.c — PHP_FUNCTION(ceil)
 */
final class MathCeil
{
    private const ABI_CEIL = 'phpc_ceil';

    private const HELPER_PATH = '/ext/standard/CeilJitHelper.php';

    private const CEIL_HELPER = 'PHPCompiler\\ext\\standard\\CeilJitHelper::ceilArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::CEIL_HELPER,
    ];

    private const BRIDGE_ENTRY = 'ceil_bridge_entry';

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
            return JitCeilKernel::invoke($context, $num);
        }

        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_CEIL),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_CEIL);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_CEIL, $probe);

            return;
        }

        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_CEIL,
            self::BRIDGE_ENTRY,
            [$double],
            $double,
            self::CEIL_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#27003'
        );
    }
}
