<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for abs() via AbsJitHelper PHP (#15175).
 *
 * Replaces inline LLVM select/negate in ext/standard/abs.php.
 * SSOT: {@see \PHPCompiler\ext\standard\AbsJitHelper}.
 * php-src: ext/standard/math.c — PHP_FUNCTION(abs)
 */
final class MathAbs
{
    private const ABI_ABS_DOUBLE = 'phpc_abs_double';

    private const ABI_ABS_LONG = 'phpc_abs_long';

    private const HELPER_PATH = '/ext/standard/AbsJitHelper.php';

    private const ABS_DOUBLE_HELPER = 'PHPCompiler\\ext\\standard\\AbsJitHelper::absDoubleArgv';

    private const ABS_LONG_HELPER = 'PHPCompiler\\ext\\standard\\AbsJitHelper::absLongArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ABS_DOUBLE_HELPER,
        self::ABS_LONG_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implementDouble($context);
        self::implementLong($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invokeDouble(Context $context, Value $num): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_ABS_DOUBLE),
            $num
        );
    }

    public static function invokeLong(Context $context, Value $num): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_ABS_LONG),
            $num
        );
    }

    private static function implementDouble(Context $context): void
    {
        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_ABS_DOUBLE,
            'abs_double_bridge_entry',
            [$double],
            $double,
            self::ABS_DOUBLE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15175'
        );
    }

    private static function implementLong(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_ABS_LONG,
            'abs_long_bridge_entry',
            [$i64],
            $i64,
            self::ABS_LONG_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15175'
        );
    }
}
