<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\ext\standard\JitAsinhKernel;
use PHPLLVM\Value;

/**
 * JIT/AOT link for asinh() via AsinhJitHelper PHP (#15221, #27058).
 *
 * Embed + thin standalone AOT: {@see AsinhJitHelper} via {@see JitVmHelperLink}
 * (Ceil/Sqrt #20664 / #27005 cosh shape — double via helper result coerce).
 * Nested helper compile: libc leaf without re-entering AsinhJitHelper.
 * php-src: ext/standard/math.c — PHP_FUNCTION(asinh)
 */
final class MathAsinh
{
    private const ABI_ASINH = 'phpc_asinh';

    private const HELPER_PATH = '/ext/standard/AsinhJitHelper.php';

    private const ASINH_HELPER = 'PHPCompiler\\ext\\standard\\AsinhJitHelper::asinhArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ASINH_HELPER,
    ];

    private const BRIDGE_ENTRY = 'asinh_bridge_entry';

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
            return JitAsinhKernel::invoke($context, $num);
        }

        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_ASINH),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_ASINH);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_ASINH, $probe);

            return;
        }

        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_ASINH,
            self::BRIDGE_ENTRY,
            [$double],
            $double,
            self::ASINH_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#27058'
        );
    }
}
