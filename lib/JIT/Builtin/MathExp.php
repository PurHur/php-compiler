<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for exp() via {@code llvm.exp.f64} (#36386).
 *
 * Peer of {@see MathSin} / {@see MathFloor}: avoid NestedJIT helper objects on
 * the AOT hot path. php-src {@code ext/standard/math.c} {@code PHP_FUNCTION(exp)}
 * → C {@code exp}; the LLVM intrinsic matches IEEE libm behaviour.
 * The PHP Taylor/Horner helper remains for NestedJIT-safe reference only.
 */
final class MathExp
{
    private const LLVM_EXP = 'llvm.exp.f64';

    /** Legacy ABI kept as a thin intrinsic wrapper for any external callers. */
    private const ABI_EXP = 'phpc_exp';

    private const BRIDGE_ENTRY = 'exp_llvm_f64_entry';

    public static function ensureLinked(Context $context): void
    {
        self::llvmExpIntrinsic($context);
        self::ensurePhpcExpBridge($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $num): Value
    {
        return $context->builder->call(self::llvmExpIntrinsic($context), $num);
    }

    private static function llvmExpIntrinsic(Context $context): LlvmFunction
    {
        $func = $context->module->getNamedFunction(self::LLVM_EXP);
        if (null !== $func) {
            return $func;
        }
        $double = $context->getTypeFromString('double');

        return $context->module->addFunction(
            self::LLVM_EXP,
            $context->context->functionType($double, false, $double)
        );
    }

    /**
     * Define {@code phpc_exp} → {@code llvm.exp.f64} when missing. Skip if a
     * prior NestedJIT bridge already filled the symbol (cannot replace LLVM
     * bodies); {@see invoke} never calls that stale path.
     */
    private static function ensurePhpcExpBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_EXP);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_EXP, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $double = $context->getTypeFromString('double');
        $fn = $probe;
        if (null === $fn) {
            $fn = $context->module->addFunction(
                self::ABI_EXP,
                $context->context->functionType($double, false, $double)
            );
        }
        $entry = $fn->appendBasicBlock(self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(
            $context->builder->call(self::llvmExpIntrinsic($context), $fn->getParam(0))
        );
        $context->registerFunction(self::ABI_EXP, $fn);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
