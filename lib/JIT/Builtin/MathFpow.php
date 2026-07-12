<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\UserScriptAotDeferNestedJit;
use PHPLLVM\Value;

/**
 * JIT/AOT link for fpow() / float pow() via FpowJitHelper PHP (#15189).
 *
 * Replaces libc `pow` LLVM lookup in ext/standard/fpow.php and JitPow.php.
 * SSOT: {@see \PHPCompiler\ext\standard\FpowJitHelper}.
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
        // User-script AOT: nested php-in-PHP helpers truncate float params (#17279, #15407).
        // Nested helper compile: FpowJitHelper → pow() → here; use libc leaf (#15189).
        if (UserScriptAotDeferNestedJit::shouldDefer($context) || NestedJitCompileScope::isActive()) {
            return self::invokeLibcPow($context, $num, $exponent);
        }

        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_FPOW),
            $num,
            $exponent
        );
    }

    private static function invokeLibcPow(Context $context, Value $num, Value $exponent): Value
    {
        $double = $context->getTypeFromString('double');
        $abiName = 'pow';
        $fn = $context->module->getNamedFunction($abiName);
        if (null === $fn) {
            try {
                $fn = $context->lookupFunction($abiName);
            } catch (\Throwable) {
                $ft = $context->context->functionType($double, false, $double, $double);
                $fn = $context->module->addFunction($abiName, $ft);
                $context->registerFunction($abiName, $fn);
            }
        }

        return $context->builder->call($fn, $num, $exponent);
    }

    private static function implement(Context $context): void
    {
        if (UserScriptAotDeferNestedJit::shouldDefer($context) || NestedJitCompileScope::isActive()) {
            return;
        }

        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_FPOW,
            'fpow_bridge_entry',
            [$double, $double],
            $double,
            self::FPOW_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15189'
        );
    }
}
