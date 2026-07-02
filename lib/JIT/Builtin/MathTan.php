<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for tan() via TanJitHelper PHP (#15088).
 *
 * Replaces libc `tan` LLVM lookup in ext/standard/tan.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmMath}.
 * php-src: ext/standard/math.c — PHP_FUNCTION(tan)
 */
final class MathTan
{
    private const ABI_TAN = 'phpc_tan';

    private const HELPER_PATH = '/ext/standard/TanJitHelper.php';

    private const TAN_HELPER = 'PHPCompiler\\ext\\standard\\TanJitHelper::tanArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::TAN_HELPER,
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
            $context->lookupFunction(self::ABI_TAN),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_TAN,
            'tan_bridge_entry',
            [$double],
            $double,
            self::TAN_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15088'
        );
    }
}
