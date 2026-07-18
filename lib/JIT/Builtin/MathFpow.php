<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\ext\standard\JitFpowKernel;
use PHPLLVM\Value;

/**
 * JIT/AOT link for fpow() / float pow() via FpowJitHelper PHP (#15189, #19259, #20034, #20664).
 *
 * Embed + thin standalone AOT: {@see FpowJitHelper} via {@see JitVmHelperLink}
 * (Rename #20603 shape — no thin libc ABI fork; double results via {@see JitNestedHelperCoerce::extractDoubleFromHelperResult}).
 * Nested helper compile: libc leaf without re-entering FpowJitHelper (#17279).
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
        // Nested helper compile of unrelated units that still call pow(): libc leaf
        // without re-entering FpowJitHelper (#17279, #19259).
        if (NestedJitCompileScope::isActive()) {
            return JitFpowKernel::invoke($context, $num, $exponent);
        }

        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_FPOW),
            $num,
            $exponent
        );
    }

    private static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

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
            '#20664'
        );
    }
}
