<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for sin() via SinJitHelper PHP (#15086).
 *
 * Replaces libc `sin` LLVM lookup in ext/standard/sin.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmMath}.
 * php-src: ext/standard/math.c — PHP_FUNCTION(sin)
 */
final class MathSin
{
    private const ABI_SIN = 'phpc_sin';

    private const HELPER_PATH = '/ext/standard/SinJitHelper.php';

    private const SIN_HELPER = 'PHPCompiler\\ext\\standard\\SinJitHelper::sinArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::SIN_HELPER,
    ];

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
            $context->lookupFunction(self::ABI_SIN),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_SIN,
            'sin_bridge_entry',
            [$double],
            $double,
            self::SIN_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15086'
        );
    }
}
