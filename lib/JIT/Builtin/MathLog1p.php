<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\ext\standard\JitLog1pKernel;
use PHPLLVM\Value;

/**
 * JIT/AOT link for log1p() via Log1pJitHelper PHP (#15157, #27057).
 *
 * Embed + thin standalone AOT: {@see Log1pJitHelper} via {@see JitVmHelperLink}
 * (Ceil/Sqrt #20664 / #27047 exp shape — double via helper result coerce).
 * Nested helper compile: libc leaf without re-entering Log1pJitHelper.
 * php-src: ext/standard/math.c — PHP_FUNCTION(log1p)
 */
final class MathLog1p
{
    private const ABI_LOG1P = 'phpc_log1p';

    private const HELPER_PATH = '/ext/standard/Log1pJitHelper.php';

    private const LOG1P_HELPER = 'PHPCompiler\\ext\\standard\\Log1pJitHelper::log1pArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::LOG1P_HELPER,
    ];

    private const BRIDGE_ENTRY = 'log1p_bridge_entry';

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
            return JitLog1pKernel::invoke($context, $num);
        }

        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_LOG1P),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_LOG1P);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_LOG1P, $probe);

            return;
        }

        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_LOG1P,
            self::BRIDGE_ENTRY,
            [$double],
            $double,
            self::LOG1P_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#27057'
        );
    }
}
