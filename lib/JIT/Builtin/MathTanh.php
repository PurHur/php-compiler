<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\ext\standard\JitTanhKernel;
use PHPLLVM\Value;

/**
 * JIT/AOT link for tanh() via TanhJitHelper PHP (#15156, #27126).
 *
 * Embed + thin standalone AOT: {@see TanhJitHelper} via {@see JitVmHelperLink}
 * (Ceil/Sqrt #20664 / #27005 cosh shape — double via helper result coerce).
 * Nested helper compile: libc leaf without re-entering TanhJitHelper.
 * php-src: ext/standard/math.c — PHP_FUNCTION(tanh)
 */
final class MathTanh
{
    private const ABI_TANH = 'phpc_tanh';

    private const HELPER_PATH = '/ext/standard/TanhJitHelper.php';

    private const TANH_HELPER = 'PHPCompiler\\ext\\standard\\TanhJitHelper::tanhArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::TANH_HELPER,
    ];

    private const BRIDGE_ENTRY = 'tanh_bridge_entry';

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
            return JitTanhKernel::invoke($context, $num);
        }

        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_TANH),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_TANH);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_TANH, $probe);

            return;
        }

        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_TANH,
            self::BRIDGE_ENTRY,
            [$double],
            $double,
            self::TANH_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#27126'
        );
    }
}
