<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for tanh() via TanhJitHelper PHP (#15156).
 *
 * Replaces libc `tanh` LLVM lookup in ext/standard/tanh.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmMath}.
 * php-src: ext/standard/math.c — PHP_FUNCTION(tanh)
 */
final class MathTanh
{
    private const ABI_TANH = 'phpc_tanh';

    private const HELPER_PATH = '/ext/standard/TanhJitHelper.php';

    private const TANH_HELPER = 'PHPCompiler\\ext\\standard\\TanhJitHelper::tanhArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::TANH_HELPER,
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
            $context->lookupFunction(self::ABI_TANH),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_TANH,
            'tanh_bridge_entry',
            [$double],
            $double,
            self::TANH_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15156'
        );
    }
}
