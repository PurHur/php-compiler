<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for cos() via CosJitHelper PHP (#15087).
 *
 * Replaces libc `cos` LLVM lookup in ext/standard/cos.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmMath}.
 * php-src: ext/standard/math.c — PHP_FUNCTION(cos)
 */
final class MathCos
{
    private const ABI_COS = 'phpc_cos';

    private const HELPER_PATH = '/ext/standard/CosJitHelper.php';

    private const COS_HELPER = 'PHPCompiler\\ext\\standard\\CosJitHelper::cosArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::COS_HELPER,
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
            $context->lookupFunction(self::ABI_COS),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_COS,
            'cos_bridge_entry',
            [$double],
            $double,
            self::COS_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15087'
        );
    }
}
