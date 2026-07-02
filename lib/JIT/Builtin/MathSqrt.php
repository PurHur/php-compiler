<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for sqrt() via SqrtJitHelper PHP (#15115).
 *
 * Replaces libc `sqrt` LLVM lookup in ext/standard/sqrt.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmMath}.
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
        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_SQRT,
            'sqrt_bridge_entry',
            [$double],
            $double,
            self::SQRT_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15115'
        );
    }
}
