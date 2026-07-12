<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\UserScriptAotDeferNestedJit;
use PHPLLVM\Value;

/**
 * JIT/AOT link for nextafter() via NextafterJitHelper PHP (#15062).
 *
 * Replaces libc `nextafter` LLVM lookup in ext/standard/nextafter.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmMath}.
 * php-src: ext/standard/math.c — PHP_FUNCTION(nextafter)
 */
final class MathNextafter
{
    private const ABI_NEXTAFTER = 'phpc_nextafter';

    private const HELPER_PATH = '/ext/standard/NextafterJitHelper.php';

    private const NEXTAFTER_HELPER = 'PHPCompiler\\ext\\standard\\NextafterJitHelper::nextafterArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::NEXTAFTER_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $num, Value $next): Value
    {
        if (UserScriptAotDeferNestedJit::shouldDefer($context) || NestedJitCompileScope::isActive()) {
            return self::invokeLibcNextafter($context, $num, $next);
        }

        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_NEXTAFTER),
            $num,
            $next
        );
    }

    private static function invokeLibcNextafter(Context $context, Value $num, Value $next): Value
    {
        $double = $context->getTypeFromString('double');
        $abiName = 'nextafter';
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

        return $context->builder->call($fn, $num, $next);
    }

    private static function implement(Context $context): void
    {
        if (UserScriptAotDeferNestedJit::shouldDefer($context) || NestedJitCompileScope::isActive()) {
            return;
        }

        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_NEXTAFTER,
            'nextafter_bridge_entry',
            [$double, $double],
            $double,
            self::NEXTAFTER_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15062'
        );
    }
}
