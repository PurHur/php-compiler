<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for fmod() via FmodJitHelper PHP (#15072).
 *
 * Replaces libc `fmod` LLVM lookup in ext/standard/fmod.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmMath}.
 * php-src: ext/standard/math.c — PHP_FUNCTION(fmod)
 */
final class MathFmod
{
    private const ABI_FMOD = 'phpc_fmod';

    private const HELPER_PATH = '/ext/standard/FmodJitHelper.php';

    private const FMOD_HELPER = 'PHPCompiler\\ext\\standard\\FmodJitHelper::fmodArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::FMOD_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $num1, Value $num2): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_FMOD),
            $num1,
            $num2
        );
    }

    private static function implement(Context $context): void
    {
        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_FMOD,
            'fmod_bridge_entry',
            [$double, $double],
            $double,
            self::FMOD_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15072'
        );
    }
}
