<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for sin() via {@code llvm.sin.f64} (#36386).
 *
 * Peer of {@see MathSqrt} / {@see MathFloor}: avoid NestedJIT helper objects on
 * the AOT hot path. php-src {@code ext/standard/math.c} {@code PHP_FUNCTION(sin)}
 * → C {@code sin}; the LLVM intrinsic matches IEEE libm behaviour.
 * The PHP Cody–Waite helper remains for NestedJIT-safe reference only.
 */
final class MathSin
{
    private const LLVM_SIN = 'llvm.sin.f64';

    /** Legacy ABI kept as a thin intrinsic wrapper for any external callers. */
    private const ABI_SIN = 'phpc_sin';

    private const BRIDGE_ENTRY = 'sin_llvm_f64_entry';

    public static function ensureLinked(Context $context): void
    {
        self::llvmSinIntrinsic($context);
        self::ensurePhpcSinBridge($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $num): Value
    {
        return $context->builder->call(self::llvmSinIntrinsic($context), $num);
    }

    private static function llvmSinIntrinsic(Context $context): LlvmFunction
    {
        $func = $context->module->getNamedFunction(self::LLVM_SIN);
        if (null !== $func) {
            return $func;
        }
        $double = $context->getTypeFromString('double');

        return $context->module->addFunction(
            self::LLVM_SIN,
            $context->context->functionType($double, false, $double)
        );
    }

    /**
     * Define {@code phpc_sin} → {@code llvm.sin.f64} when missing. Skip if a
     * prior NestedJIT bridge already filled the symbol (cannot replace LLVM
     * bodies); {@see invoke} never calls that stale path.
     */
    private static function ensurePhpcSinBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_SIN);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_SIN, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $double = $context->getTypeFromString('double');
        $fn = $probe;
        if (null === $fn) {
            $fn = $context->module->addFunction(
                self::ABI_SIN,
                $context->context->functionType($double, false, $double)
            );
        }
        $entry = $fn->appendBasicBlock(self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(
            $context->builder->call(self::llvmSinIntrinsic($context), $fn->getParam(0))
        );
        $context->registerFunction(self::ABI_SIN, $fn);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
