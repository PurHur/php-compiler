<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for is_infinite() via IsInfiniteJitHelper PHP (#15174).
 *
 * Replaces libc `isinf` LLVM lookup in ext/standard/is_infinite.php.
 * SSOT: {@see \PHPCompiler\ext\standard\IsInfiniteJitHelper}.
 * php-src: ext/standard/math.c — PHP_FUNCTION(is_infinite)
 */
final class MathIsInfinite
{
    private const ABI_IS_INFINITE = 'phpc_is_infinite';

    private const HELPER_PATH = '/ext/standard/IsInfiniteJitHelper.php';

    private const IS_INFINITE_HELPER = 'PHPCompiler\\ext\\standard\\IsInfiniteJitHelper::isInfiniteArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::IS_INFINITE_HELPER,
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
            $context->lookupFunction(self::ABI_IS_INFINITE),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        $double = $context->getTypeFromString('double');
        $i1 = $context->getTypeFromString('int1');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_IS_INFINITE,
            'is_infinite_bridge_entry',
            [$double],
            $i1,
            self::IS_INFINITE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15174'
        );
    }
}
