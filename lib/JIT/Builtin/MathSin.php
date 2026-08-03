<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\ext\standard\JitSinKernel;
use PHPLLVM\Value;

/**
 * JIT/AOT link for sin() via SinJitHelper PHP (#15086, #27048).
 *
 * Embed + thin standalone AOT: {@see SinJitHelper} via {@see JitVmHelperLink}
 * (Ceil/Sqrt #20664 / #27003 shape — double via helper result coerce).
 * Nested helper compile: libc leaf without re-entering SinJitHelper.
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
        if (NestedJitCompileScope::isActive()) {
            return JitSinKernel::invoke($context, $num);
        }

        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_SIN),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

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
            '#27048'
        );
    }
}
