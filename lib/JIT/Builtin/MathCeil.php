<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for ceil() via {@code llvm.ceil.f64} (#36386).
 *
 * Peer of {@see MathFloor} / {@see MathSqrt}: avoid NestedJIT helper objects on
 * the AOT hot path. php-src {@code ext/standard/math.c} {@code PHP_FUNCTION(ceil)}
 * → C {@code ceil}; the LLVM intrinsic matches IEEE toward-+∞ rounding.
 * The PHP trunc helper remains for NestedJIT-safe reference only.
 */
final class MathCeil
{
    private const LLVM_CEIL = 'llvm.ceil.f64';

    /** Legacy ABI kept as a thin intrinsic wrapper for any external callers. */
    private const ABI_CEIL = 'phpc_ceil';

    private const BRIDGE_ENTRY = 'ceil_llvm_f64_entry';

    public static function ensureLinked(Context $context): void
    {
        self::llvmCeilIntrinsic($context);
        self::ensurePhpcCeilBridge($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $num): Value
    {
        return $context->builder->call(self::llvmCeilIntrinsic($context), $num);
    }

    private static function llvmCeilIntrinsic(Context $context): LlvmFunction
    {
        $func = $context->module->getNamedFunction(self::LLVM_CEIL);
        if (null !== $func) {
            return $func;
        }
        $double = $context->getTypeFromString('double');

        return $context->module->addFunction(
            self::LLVM_CEIL,
            $context->context->functionType($double, false, $double)
        );
    }

    /**
     * Define {@code phpc_ceil} → {@code llvm.ceil.f64} when missing. Skip if a
     * prior NestedJIT bridge already filled the symbol (cannot replace LLVM
     * bodies); {@see invoke} never calls that stale path.
     */
    private static function ensurePhpcCeilBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_CEIL);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_CEIL, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $double = $context->getTypeFromString('double');
        $fn = $probe;
        if (null === $fn) {
            $fn = $context->module->addFunction(
                self::ABI_CEIL,
                $context->context->functionType($double, false, $double)
            );
        }
        $entry = $fn->appendBasicBlock(self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(
            $context->builder->call(self::llvmCeilIntrinsic($context), $fn->getParam(0))
        );
        $context->registerFunction(self::ABI_CEIL, $fn);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
