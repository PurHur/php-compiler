<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\ext\standard\JitCoshKernel;
use PHPLLVM\Value;

/**
 * JIT/AOT link for cosh() via CoshJitHelper PHP (#15156, #27005).
 *
 * Embed + thin standalone AOT: {@see CoshJitHelper} via {@see JitVmHelperLink}
 * (Ceil/Sqrt #20664 / #27003 shape — double via helper result coerce).
 * Nested helper compile: libc leaf without re-entering CoshJitHelper.
 * php-src: ext/standard/math.c — PHP_FUNCTION(cosh)
 */
final class MathCosh
{
    private const ABI_COSH = 'phpc_cosh';

    private const HELPER_PATH = '/ext/standard/CoshJitHelper.php';

    private const COSH_HELPER = 'PHPCompiler\\ext\\standard\\CoshJitHelper::coshArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::COSH_HELPER,
    ];

    private const BRIDGE_ENTRY = 'cosh_bridge_entry';

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
            return JitCoshKernel::invoke($context, $num);
        }

        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_COSH),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_COSH);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_COSH, $probe);

            return;
        }

        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_COSH,
            self::BRIDGE_ENTRY,
            [$double],
            $double,
            self::COSH_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#27005'
        );
    }
}
