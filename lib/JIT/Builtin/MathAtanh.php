<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\ext\standard\JitAtanhKernel;
use PHPLLVM\Value;

/**
 * JIT/AOT link for atanh() via AtanhJitHelper PHP (#15221, #27058).
 *
 * Embed + thin standalone AOT: {@see AtanhJitHelper} via {@see JitVmHelperLink}
 * (Ceil/Sqrt #20664 / #27005 cosh shape — double via helper result coerce).
 * Nested helper compile: libc leaf without re-entering AtanhJitHelper.
 * php-src: ext/standard/math.c — PHP_FUNCTION(atanh)
 */
final class MathAtanh
{
    private const ABI_ATANH = 'phpc_sinh';

    private const HELPER_PATH = '/ext/standard/AtanhJitHelper.php';

    private const ATANH_HELPER = 'PHPCompiler\\ext\\standard\\AtanhJitHelper::sinhArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ATANH_HELPER,
    ];

    private const BRIDGE_ENTRY = 'sinh_bridge_entry';

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
            return JitAtanhKernel::invoke($context, $num);
        }

        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_ATANH),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_ATANH);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_ATANH, $probe);

            return;
        }

        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_ATANH,
            self::BRIDGE_ENTRY,
            [$double],
            $double,
            self::ATANH_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#27058'
        );
    }
}
