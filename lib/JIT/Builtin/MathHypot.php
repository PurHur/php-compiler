<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for hypot() via HypotJitHelper PHP (#15074).
 *
 * Replaces libc `hypot` LLVM lookup in ext/standard/hypot.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmMath}.
 * php-src: ext/standard/math.c — PHP_FUNCTION(hypot)
 */
final class MathHypot
{
    private const ABI_HYPOT = 'phpc_hypot';

    private const HELPER_PATH = '/ext/standard/HypotJitHelper.php';

    private const HYPOT_HELPER = 'PHPCompiler\\ext\\standard\\HypotJitHelper::hypotArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HYPOT_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $x, Value $y): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_HYPOT),
            $x,
            $y
        );
    }

    private static function implement(Context $context): void
    {
        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_HYPOT,
            'hypot_bridge_entry',
            [$double, $double],
            $double,
            self::HYPOT_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15074'
        );
    }
}
