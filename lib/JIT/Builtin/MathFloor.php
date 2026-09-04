<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for floor() via {@code llvm.floor.f64} (#36386).
 *
 * Peer of {@see MathSqrt} / {@see MathAbs}: avoid NestedJIT helper objects on
 * the AOT hot path. php-src {@code ext/standard/math.c} {@code PHP_FUNCTION(floor)}
 * → C {@code floor}; the LLVM intrinsic matches IEEE toward-−∞ rounding.
 * The PHP trunc helper remains for NestedJIT-safe reference only.
 */
final class MathFloor
{
    private const LLVM_FLOOR = 'llvm.floor.f64';

    /** Legacy ABI kept as a thin intrinsic wrapper for any external callers. */
    private const ABI_FLOOR = 'phpc_floor';

    private const BRIDGE_ENTRY = 'floor_llvm_f64_entry';

    public static function ensureLinked(Context $context): void
    {
        self::llvmFloorIntrinsic($context);
        self::ensurePhpcFloorBridge($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $num): Value
    {
        return $context->builder->call(self::llvmFloorIntrinsic($context), $num);
    }

    private static function llvmFloorIntrinsic(Context $context): LlvmFunction
    {
        $func = $context->module->getNamedFunction(self::LLVM_FLOOR);
        if (null !== $func) {
            return $func;
        }
        $double = $context->getTypeFromString('double');

        return $context->module->addFunction(
            self::LLVM_FLOOR,
            $context->context->functionType($double, false, $double)
        );
    }

    /**
     * Define {@code phpc_floor} → {@code llvm.floor.f64} when missing. Skip if a
     * prior NestedJIT bridge already filled the symbol (cannot replace LLVM
     * bodies); {@see invoke} never calls that stale path.
     */
    private static function ensurePhpcFloorBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_FLOOR);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_FLOOR, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $double = $context->getTypeFromString('double');
        $fn = $probe;
        if (null === $fn) {
            $fn = $context->module->addFunction(
                self::ABI_FLOOR,
                $context->context->functionType($double, false, $double)
            );
        }
        $entry = $fn->appendBasicBlock(self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(
            $context->builder->call(self::llvmFloorIntrinsic($context), $fn->getParam(0))
        );
        $context->registerFunction(self::ABI_FLOOR, $fn);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
