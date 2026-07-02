<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for sinh() via SinhJitHelper PHP (#15156).
 *
 * Replaces libc `sinh` LLVM lookup in ext/standard/sinh.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmMath}.
 * php-src: ext/standard/math.c — PHP_FUNCTION(sinh)
 */
final class MathSinh
{
    private const ABI_SINH = 'phpc_sinh';

    private const HELPER_PATH = '/ext/standard/SinhJitHelper.php';

    private const SINH_HELPER = 'PHPCompiler\\ext\\standard\\SinhJitHelper::sinhArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::SINH_HELPER,
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
            $context->lookupFunction(self::ABI_SINH),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_SINH,
            'sinh_bridge_entry',
            [$double],
            $double,
            self::SINH_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15156'
        );
    }
}
