<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for cosh() via libm {@code cosh(3)} (#36386).
 *
 * Peer of {@see MathSinh} / {@see MathTan}: avoid NestedJIT helper objects on
 * the AOT hot path. php-src {@code ext/standard/math.c} {@code PHP_FUNCTION(cosh)}
 * → C {@code cosh}. LLVM 9 has no cosh intrinsic (unlike sin/cos/exp/log),
 * so declare the libc symbol the linker already pulls via {@code -lm}
 * ({@see NumberFormatRuntime} {@code floor} shape / {@see MathSinh}).
 * The PHP fdlibm helper remains for NestedJIT-safe reference only.
 */
final class MathCosh
{
    /** Libc cosh(3) — no LLVM 9 intrinsic peer of sin/cos. */
    private const LIBC_COSH = 'cosh';

    /** Legacy ABI kept as a thin libm wrapper for any external callers. */
    private const ABI_COSH = 'phpc_cosh';

    private const BRIDGE_ENTRY = 'cosh_libm_f64_entry';

    public static function ensureLinked(Context $context): void
    {
        self::libcCoshDecl($context);
        self::ensurePhpcCoshBridge($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $num): Value
    {
        return $context->builder->call(self::libcCoshDecl($context), $num);
    }

    private static function libcCoshDecl(Context $context): LlvmFunction
    {
        $func = $context->module->getNamedFunction(self::LIBC_COSH);
        if (null !== $func) {
            $context->registerFunction(self::LIBC_COSH, $func);

            return $func;
        }
        $double = $context->getTypeFromString('double');
        $fn = $context->module->addFunction(
            self::LIBC_COSH,
            $context->context->functionType($double, false, $double)
        );
        $context->registerFunction(self::LIBC_COSH, $fn);

        return $fn;
    }

    /**
     * Define {@code phpc_cosh} → libm {@code cosh} when missing. Skip if a prior
     * NestedJIT bridge already filled the symbol (cannot replace LLVM bodies);
     * {@see invoke} never calls that stale path.
     */
    private static function ensurePhpcCoshBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_COSH);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_COSH, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $double = $context->getTypeFromString('double');
        $fn = $probe;
        if (null === $fn) {
            $fn = $context->module->addFunction(
                self::ABI_COSH,
                $context->context->functionType($double, false, $double)
            );
        }
        $entry = $fn->appendBasicBlock(self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(
            $context->builder->call(self::libcCoshDecl($context), $fn->getParam(0))
        );
        $context->registerFunction(self::ABI_COSH, $fn);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
