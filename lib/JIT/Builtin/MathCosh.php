<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for cosh() via CoshJitHelper PHP (#15156).
 *
 * Replaces libc `cosh` LLVM lookup in ext/standard/cosh.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmMath}.
 * php-src: ext/standard/math.c — PHP_FUNCTION(cosh)
 */
final class MathCosh
{
    private const ABI_COSH = 'phpc_cosh';

    private const HELPER_PATH = '/ext/standard/CoshJitHelper.php';

    private const COSH_HELPER = 'PHPCompiler\\ext\\standard\\CoshJitHelper::coshArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::COSH_HELPER,
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
            $context->lookupFunction(self::ABI_COSH),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_COSH,
            'cosh_bridge_entry',
            [$double],
            $double,
            self::COSH_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15156'
        );
    }
}
