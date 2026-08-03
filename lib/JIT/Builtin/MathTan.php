<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\ext\standard\JitTanKernel;
use PHPLLVM\Value;

/**
 * JIT/AOT link for tan() via TanJitHelper PHP (#15088, #27048).
 *
 * Embed + thin standalone AOT: {@see TanJitHelper} via {@see JitVmHelperLink}
 * (Ceil/Sqrt #20664 / #27003 shape — double via helper result coerce).
 * Nested helper compile: libc leaf without re-entering TanJitHelper.
 * php-src: ext/standard/math.c — PHP_FUNCTION(tan)
 */
final class MathTan
{
    private const ABI_TAN = 'phpc_tan';

    private const HELPER_PATH = '/ext/standard/TanJitHelper.php';

    private const TAN_HELPER = 'PHPCompiler\\ext\\standard\\TanJitHelper::tanArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::TAN_HELPER,
    ];

    private const BRIDGE_ENTRY = 'tan_bridge_entry';

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
            return JitTanKernel::invoke($context, $num);
        }

        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_TAN),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_TAN);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_TAN, $probe);

            return;
        }

        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_TAN,
            self::BRIDGE_ENTRY,
            [$double],
            $double,
            self::TAN_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#27048'
        );
    }
}
