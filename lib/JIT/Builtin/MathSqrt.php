<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\ext\standard\JitSqrtKernel;
use PHPLLVM\Value;

/**
 * JIT/AOT link for sqrt() via SqrtJitHelper PHP (#15115, #20664).
 *
 * Embed + thin standalone AOT: {@see SqrtJitHelper} via {@see JitVmHelperLink}
 * (Rename #20603 shape — double via {@see JitNestedHelperCoerce::extractDoubleFromHelperResult}).
 * Nested helper compile: libc leaf without re-entering SqrtJitHelper.
 * php-src: ext/standard/math.c — PHP_FUNCTION(sqrt)
 */
final class MathSqrt
{
    private const ABI_SQRT = 'phpc_sqrt';

    private const HELPER_PATH = '/ext/standard/SqrtJitHelper.php';

    private const SQRT_HELPER = 'PHPCompiler\\ext\\standard\\SqrtJitHelper::sqrtArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::SQRT_HELPER,
    ];

    private const BRIDGE_ENTRY = 'sqrt_bridge_entry';

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
            return JitSqrtKernel::invoke($context, $num);
        }

        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_SQRT),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_SQRT);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_SQRT, $probe);

            return;
        }

        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_SQRT,
            self::BRIDGE_ENTRY,
            [$double],
            $double,
            self::SQRT_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#20664'
        );
    }
}
