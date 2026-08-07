<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for tanh() via TanhJitHelper PHP (#15156, #27126, #28459).
 *
 * Helper compile: {@see JitVmHelperLink::ensureBridge} (MathCosh #28446 / MathSinh #28418 shape).
 * NestedJIT no longer needs a libc tanh(3) kernel — helper uses NestedJIT-safe exp.
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
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_TANH),
            $num
        );
    }

    private static function implement(Context $context): void
    {
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
            '#28459'
        );
    }
}
