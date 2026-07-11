<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for floor() via FloorJitHelper PHP (#15128).
 *
 * Replaces libc `floor` LLVM lookup in ext/standard/floor.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmMath}.
 * php-src: ext/standard/math.c — PHP_FUNCTION(floor)
 */
final class MathFloor
{
    private const ABI_FLOOR = 'phpc_floor';

    private const HELPER_PATH = '/ext/standard/FloorJitHelper.php';

    private const FLOOR_HELPER = 'PHPCompiler\\ext\\standard\\FloorJitHelper::floorArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::FLOOR_HELPER,
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
            $context->lookupFunction(self::ABI_FLOOR),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_FLOOR,
            'floor_bridge_entry',
            [$double],
            $double,
            self::FLOOR_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15128'
        );
    }
}
