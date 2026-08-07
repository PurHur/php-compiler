<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for fpow() / float pow() via FpowJitHelper PHP (#15189, #19259, #20034, #20664, #28674).
 *
 * Helper compile: {@see JitVmHelperLink::ensureBridge} (MathLog10 #28642 / MathLog #28574 shape).
 * NestedJIT no longer needs a libc pow(3) kernel — helper uses NestedJIT-safe log+exp.
 * SSOT: {@see \PHPCompiler\ext\standard\VmMath}.
 * php-src: ext/standard/math.c — PHP_FUNCTION(fpow)
 */
final class MathFpow
{
    private const ABI_FPOW = 'phpc_fpow';

    private const HELPER_PATH = '/ext/standard/FpowJitHelper.php';

    private const FPOW_HELPER = 'PHPCompiler\\ext\\standard\\FpowJitHelper::fpowArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::FPOW_HELPER,
    ];

    private const BRIDGE_ENTRY = 'fpow_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $num, Value $exponent): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_FPOW),
            $num,
            $exponent
        );
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_FPOW);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_FPOW, $probe);

            return;
        }

        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_FPOW,
            self::BRIDGE_ENTRY,
            [$double, $double],
            $double,
            self::FPOW_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#28674'
        );
    }
}
