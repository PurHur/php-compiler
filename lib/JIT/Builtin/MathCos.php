<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for cos() via {@code llvm.cos.f64} (#36386).
 *
 * Peer of {@see MathSin} / {@see MathFloor}: avoid NestedJIT helper objects on
 * the AOT hot path. php-src {@code ext/standard/math.c} {@code PHP_FUNCTION(cos)}
 * → C {@code cos}; the LLVM intrinsic matches IEEE libm behaviour.
 * The PHP Taylor helper remains for NestedJIT-safe reference only.
 */
final class MathCos
{
    private const LLVM_COS = 'llvm.cos.f64';

    /** Legacy ABI kept as a thin intrinsic wrapper for any external callers. */
    private const ABI_COS = 'phpc_cos';

    private const BRIDGE_ENTRY = 'cos_llvm_f64_entry';

    public static function ensureLinked(Context $context): void
    {
        self::llvmCosIntrinsic($context);
        self::ensurePhpcCosBridge($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $num): Value
    {
        return $context->builder->call(self::llvmCosIntrinsic($context), $num);
    }

    private static function llvmCosIntrinsic(Context $context): LlvmFunction
    {
        $func = $context->module->getNamedFunction(self::LLVM_COS);
        if (null !== $func) {
            return $func;
        }
        $double = $context->getTypeFromString('double');

        return $context->module->addFunction(
            self::LLVM_COS,
            $context->context->functionType($double, false, $double)
        );
    }

    /**
     * Define {@code phpc_cos} → {@code llvm.cos.f64} when missing. Skip if a
     * prior NestedJIT bridge already filled the symbol (cannot replace LLVM
     * bodies); {@see invoke} never calls that stale path.
     */
    private static function ensurePhpcCosBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_COS);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_COS, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $double = $context->getTypeFromString('double');
        $fn = $probe;
        if (null === $fn) {
            $fn = $context->module->addFunction(
                self::ABI_COS,
                $context->context->functionType($double, false, $double)
            );
        }
        $entry = $fn->appendBasicBlock(self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(
            $context->builder->call(self::llvmCosIntrinsic($context), $fn->getParam(0))
        );
        $context->registerFunction(self::ABI_COS, $fn);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
