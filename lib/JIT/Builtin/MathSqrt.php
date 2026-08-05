<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for sqrt() via SqrtJitHelper PHP (#15115, #20664, #27888).
 *
 * Helper compile: {@see JitVmHelperLink::ensureBridge} (floor/ceil #27650 / fmod #27838 shape).
 * NestedJIT no longer needs a libc sqrt(3) kernel — helper inlines NestedJIT-safe Newton.
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
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_SQRT),
            $num
        );
    }

    private static function implement(Context $context): void
    {
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
            '#27888'
        );
    }
}
