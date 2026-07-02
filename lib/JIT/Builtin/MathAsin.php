<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for asin() via AsinJitHelper PHP (#15130).
 *
 * Replaces libc `asin` LLVM lookup in ext/standard/asin.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmMath}.
 * php-src: ext/standard/math.c — PHP_FUNCTION(asin)
 */
final class MathAsin
{
    private const ABI_ASIN = 'phpc_asin';

    private const HELPER_PATH = '/ext/standard/AsinJitHelper.php';

    private const ASIN_HELPER = 'PHPCompiler\\ext\\standard\\AsinJitHelper::asinArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ASIN_HELPER,
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
            $context->lookupFunction(self::ABI_ASIN),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_ASIN,
            'asin_bridge_entry',
            [$double],
            $double,
            self::ASIN_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15130'
        );
    }
}
