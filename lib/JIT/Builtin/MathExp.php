<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\ext\standard\JitExpKernel;
use PHPLLVM\Value;

/**
 * JIT/AOT link for exp() via ExpJitHelper PHP (#15116, #27047).
 *
 * Embed + thin standalone AOT: {@see ExpJitHelper} via {@see JitVmHelperLink}
 * (Ceil/Sqrt #20664 / #27003 shape — double via helper result coerce).
 * Nested helper compile: libc leaf without re-entering ExpJitHelper.
 * php-src: ext/standard/math.c — PHP_FUNCTION(exp)
 */
final class MathExp
{
    private const ABI_EXP = 'phpc_exp';

    private const HELPER_PATH = '/ext/standard/ExpJitHelper.php';

    private const EXP_HELPER = 'PHPCompiler\\ext\\standard\\ExpJitHelper::expArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::EXP_HELPER,
    ];

    private const BRIDGE_ENTRY = 'exp_bridge_entry';

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
            return JitExpKernel::invoke($context, $num);
        }

        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_EXP),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_EXP);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_EXP, $probe);

            return;
        }

        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_EXP,
            self::BRIDGE_ENTRY,
            [$double],
            $double,
            self::EXP_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#27047'
        );
    }
}
