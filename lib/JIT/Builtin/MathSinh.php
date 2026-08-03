<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\ext\standard\JitSinhKernel;
use PHPLLVM\Value;

/**
 * JIT/AOT link for sinh() via SinhJitHelper PHP (#15156, #27125).
 *
 * Embed + thin standalone AOT: {@see SinhJitHelper} via {@see JitVmHelperLink}
 * (Ceil/Sqrt #20664 / #27005 cosh shape — double via helper result coerce).
 * Nested helper compile: libc leaf without re-entering SinhJitHelper.
 * php-src: ext/standard/math.c — PHP_FUNCTION(sinh)
 */
final class MathSinh
{
    private const ABI_SINH = 'phpc_sinh';

    private const HELPER_PATH = '/ext/standard/SinhJitHelper.php';

    private const SINH_HELPER = 'PHPCompiler\\ext\\standard\\SinhJitHelper::sinhArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::SINH_HELPER,
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
            return JitSinhKernel::invoke($context, $num);
        }

        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_SINH),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_SINH);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_SINH, $probe);

            return;
        }

        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_SINH,
            self::BRIDGE_ENTRY,
            [$double],
            $double,
            self::SINH_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#27125'
        );
    }
}
