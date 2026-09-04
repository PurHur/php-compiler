<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for log10() via {@code llvm.log10.f64} (#36386).
 *
 * Peer of {@see MathLog} / {@see MathExp}: avoid NestedJIT helper objects on
 * the AOT hot path. php-src {@code ext/standard/math.c} {@code PHP_FUNCTION(log10)}
 * → C {@code log10}; the LLVM intrinsic matches IEEE libm behaviour.
 * The PHP series helper remains for NestedJIT-safe reference only.
 */
final class MathLog10
{
    private const LLVM_LOG10 = 'llvm.log10.f64';

    /** Legacy ABI kept as a thin intrinsic wrapper for any external callers. */
    private const ABI_LOG10 = 'phpc_log10';

    private const BRIDGE_ENTRY = 'log10_llvm_f64_entry';

    public static function ensureLinked(Context $context): void
    {
        self::llvmLog10Intrinsic($context);
        self::ensurePhpcLog10Bridge($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $num): Value
    {
        return $context->builder->call(self::llvmLog10Intrinsic($context), $num);
    }

    private static function llvmLog10Intrinsic(Context $context): LlvmFunction
    {
        $func = $context->module->getNamedFunction(self::LLVM_LOG10);
        if (null !== $func) {
            return $func;
        }
        $double = $context->getTypeFromString('double');

        return $context->module->addFunction(
            self::LLVM_LOG10,
            $context->context->functionType($double, false, $double)
        );
    }

    /**
     * Define {@code phpc_log10} → {@code llvm.log10.f64} when missing. Skip if a
     * prior NestedJIT bridge already filled the symbol (cannot replace LLVM
     * bodies); {@see invoke} never calls that stale path.
     */
    private static function ensurePhpcLog10Bridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_LOG10);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_LOG10, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $double = $context->getTypeFromString('double');
        $fn = $probe;
        if (null === $fn) {
            $fn = $context->module->addFunction(
                self::ABI_LOG10,
                $context->context->functionType($double, false, $double)
            );
        }
        $entry = $fn->appendBasicBlock(self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(
            $context->builder->call(self::llvmLog10Intrinsic($context), $fn->getParam(0))
        );
        $context->registerFunction(self::ABI_LOG10, $fn);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
