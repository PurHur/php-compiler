<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for sqrt() via {@code llvm.sqrt.f64} (#36386).
 *
 * The committed NestedJIT helper-runtime unit for the PHP Newton sqrt returned
 * wrong values for non-perfect squares (e.g. {@code sqrt(2.5)} → {@code 4.82e-5}
 * vs Zend {@code 1.58…}). php-src uses C {@code sqrt} ({@code Zend/zend_operators.h}
 * / {@code ext/standard/math.c} {@code PHP_FUNCTION(sqrt)} → {@code zend_csqrt});
 * the LLVM intrinsic matches that IEEE behaviour and avoids the stale object.
 */
final class MathSqrt
{
    private const LLVM_SQRT = 'llvm.sqrt.f64';

    /** Legacy ABI kept as a thin intrinsic wrapper for any external callers. */
    private const ABI_SQRT = 'phpc_sqrt';

    private const BRIDGE_ENTRY = 'sqrt_llvm_f64_entry';

    public static function ensureLinked(Context $context): void
    {
        self::llvmSqrtIntrinsic($context);
        self::ensurePhpcSqrtBridge($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $num): Value
    {
        return $context->builder->call(self::llvmSqrtIntrinsic($context), $num);
    }

    private static function llvmSqrtIntrinsic(Context $context): LlvmFunction
    {
        $func = $context->module->getNamedFunction(self::LLVM_SQRT);
        if (null !== $func) {
            return $func;
        }
        $double = $context->getTypeFromString('double');

        return $context->module->addFunction(
            self::LLVM_SQRT,
            $context->context->functionType($double, false, $double)
        );
    }

    /**
     * Define {@code phpc_sqrt} → {@code llvm.sqrt.f64} when missing. Skip if a
     * prior NestedJIT bridge already filled the symbol (cannot replace LLVM
     * bodies); {@see invoke} never calls that stale path.
     */
    private static function ensurePhpcSqrtBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_SQRT);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_SQRT, $probe);

            return;
        }

        // Peer MathAbs: save/restore insert — defining the bridge mid-lowering
        // must not steal the caller's builder (#36386).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $double = $context->getTypeFromString('double');
        $fn = $probe;
        if (null === $fn) {
            $fn = $context->module->addFunction(
                self::ABI_SQRT,
                $context->context->functionType($double, false, $double)
            );
        }
        $entry = $fn->appendBasicBlock(self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(
            $context->builder->call(self::llvmSqrtIntrinsic($context), $fn->getParam(0))
        );
        $context->registerFunction(self::ABI_SQRT, $fn);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
