<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for fpow() / float pow() via {@code llvm.pow.f64} (#36386).
 *
 * Peer of {@see MathExp} / {@see MathLog}: avoid NestedJIT helper objects on
 * the AOT hot path. php-src {@code ext/standard/math.c} {@code PHP_FUNCTION(fpow)}
 * / {@code pow_function} → C {@code pow}; the LLVM intrinsic matches IEEE libm.
 * The PHP log+exp / successive-squaring helper remains for NestedJIT-safe
 * reference only.
 */
final class MathFpow
{
    private const LLVM_POW = 'llvm.pow.f64';

    /** Legacy ABI kept as a thin intrinsic wrapper for any external callers. */
    private const ABI_FPOW = 'phpc_fpow';

    private const BRIDGE_ENTRY = 'fpow_llvm_f64_entry';

    public static function ensureLinked(Context $context): void
    {
        self::llvmPowIntrinsic($context);
        self::ensurePhpcFpowBridge($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $num, Value $exponent): Value
    {
        return $context->builder->call(self::llvmPowIntrinsic($context), $num, $exponent);
    }

    private static function llvmPowIntrinsic(Context $context): LlvmFunction
    {
        $func = $context->module->getNamedFunction(self::LLVM_POW);
        if (null !== $func) {
            return $func;
        }
        $double = $context->getTypeFromString('double');

        return $context->module->addFunction(
            self::LLVM_POW,
            $context->context->functionType($double, false, $double, $double)
        );
    }

    /**
     * Define {@code phpc_fpow} → {@code llvm.pow.f64} when missing. Skip if a
     * prior NestedJIT bridge already filled the symbol (cannot replace LLVM
     * bodies); {@see invoke} never calls that stale path.
     */
    private static function ensurePhpcFpowBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_FPOW);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_FPOW, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $double = $context->getTypeFromString('double');
        $fn = $probe;
        if (null === $fn) {
            $fn = $context->module->addFunction(
                self::ABI_FPOW,
                $context->context->functionType($double, false, $double, $double)
            );
        }
        $entry = $fn->appendBasicBlock(self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(
            $context->builder->call(
                self::llvmPowIntrinsic($context),
                $fn->getParam(0),
                $fn->getParam(1)
            )
        );
        $context->registerFunction(self::ABI_FPOW, $fn);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
