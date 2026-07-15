<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\UserScriptAotDeferNestedJit;
use PHPCompiler\ext\standard\JitFpowKernel;
use PHPLLVM\Value;

/**
 * JIT/AOT link for fpow() / float pow() via FpowJitHelper PHP (#15189, #19259).
 *
 * Embed / non-user-script: {@see FpowJitHelper} via {@see JitVmHelperLink}.
 * User-script standalone AOT + nested leaf: thin {@see JitFpowKernel} libc pow(3).
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

        if (UserScriptAotDeferNestedJit::shouldDefer($context)) {
            self::implementUserScriptKernel($context);

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
            '#19259'
        );
    }

    private static function implementUserScriptKernel(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_FPOW);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_FPOW, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $double = $context->getTypeFromString('double');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI_FPOW,
                $context->context->functionType($double, false, $double, $double)
            );

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $result = JitFpowKernel::invoke($context, $fn->getParam(0), $fn->getParam(1));
        $context->builder->returnValue($result);
        $context->registerFunction(self::ABI_FPOW, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
