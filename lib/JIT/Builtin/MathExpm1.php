<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for expm1() via Expm1JitHelper PHP (#15157).
 *
 * Replaces libc `expm1` LLVM lookup in ext/standard/expm1.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmMath}.
 * php-src: ext/standard/math.c — PHP_FUNCTION(expm1)
 */
final class MathExpm1
{
    private const ABI_EXPM1 = 'phpc_expm1';

    private const HELPER_PATH = '/ext/standard/Expm1JitHelper.php';

    private const EXPM1_HELPER = 'PHPCompiler\\ext\\standard\\Expm1JitHelper::expm1Argv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::EXPM1_HELPER,
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
            $context->lookupFunction(self::ABI_EXPM1),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_EXPM1,
            'expm1_bridge_entry',
            [$double],
            $double,
            self::EXPM1_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15157'
        );
    }
}
