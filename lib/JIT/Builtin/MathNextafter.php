<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
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
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_NEXTAFTER),
            $num,
            $next
        );
    }

    private static function implement(Context $context): void
    {
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
