<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\ext\standard\JitHypotKernel;
use PHPLLVM\Value;

/**
 * JIT/AOT link for hypot() via HypotJitHelper PHP (#15074, #20664).
 *
 * Embed + thin standalone AOT: {@see HypotJitHelper} via {@see JitVmHelperLink}
 * (Rename #20603 shape — double via {@see JitNestedHelperCoerce::extractDoubleFromHelperResult}).
 * Nested helper compile: libc leaf without re-entering HypotJitHelper.
 * php-src: ext/standard/math.c — PHP_FUNCTION(hypot)
 */
final class MathHypot
{
    private const ABI_HYPOT = 'phpc_hypot';

    private const HELPER_PATH = '/ext/standard/HypotJitHelper.php';

    private const HYPOT_HELPER = 'PHPCompiler\\ext\\standard\\HypotJitHelper::hypotArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HYPOT_HELPER,
    ];

    private const BRIDGE_ENTRY = 'hypot_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $x, Value $y): Value
    {
        if (NestedJitCompileScope::isActive()) {
            return JitHypotKernel::invoke($context, $x, $y);
        }

        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_HYPOT),
            $x,
            $y
        );
    }

    private static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_HYPOT);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_HYPOT, $probe);

            return;
        }

        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_HYPOT,
            self::BRIDGE_ENTRY,
            [$double, $double],
            $double,
            self::HYPOT_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#20664'
        );
    }
}
