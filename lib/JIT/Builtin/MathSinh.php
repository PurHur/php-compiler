<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for sinh() via SinhJitHelper PHP (#15156, #27125, #28418).
 *
 * Helper compile: {@see JitVmHelperLink::ensureBridge} (MathAtanh #28377 / MathAsinh #28355 shape).
 * NestedJIT no longer needs a libc sinh(3) kernel — helper uses NestedJIT-safe exp.
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
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_SINH),
            $num
        );
    }

    private static function implement(Context $context): void
    {
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
            '#28418'
        );
    }
}
