<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\ext\standard\JitFloorKernel;
use PHPLLVM\Value;

/**
 * JIT/AOT link for floor() via FloorJitHelper PHP (#15128, #27004).
 *
 * Embed + thin standalone AOT: {@see FloorJitHelper} via {@see JitVmHelperLink}
 * (Hypot/Sqrt #20664 / Ceil #27003 shape — double via helper result coerce).
 * Nested helper compile: libc leaf without re-entering FloorJitHelper.
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

    private const BRIDGE_ENTRY = 'floor_bridge_entry';

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
        if (NestedJitCompileScope::isActive()) {
            return JitFloorKernel::invoke($context, $num);
        }

        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_FLOOR),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_FLOOR);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_FLOOR, $probe);

            return;
        }

        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_FLOOR,
            self::BRIDGE_ENTRY,
            [$double],
            $double,
            self::FLOOR_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#27004'
        );
    }
}
