<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for asinh() via libm {@code asinh(3)} (#36386).
 *
 * Peer of {@see MathSinh} / {@see MathAsin}: avoid NestedJIT helper objects on
 * the AOT hot path. php-src {@code ext/standard/math.c} {@code PHP_FUNCTION(asinh)}
 * → C {@code asinh}. LLVM 9 has no asinh intrinsic (unlike sin/cos/exp/log),
 * so declare the libc symbol the linker already pulls via {@code -lm}
 * ({@see NumberFormatRuntime} {@code floor} shape / {@see MathSinh}).
 * The PHP fdlibm helper remains for NestedJIT-safe reference only.
 */
final class MathAsinh
{
    /** Libc asinh(3) — no LLVM 9 intrinsic peer of sin/cos. */
    private const LIBC_ASINH = 'asinh';

    /** Legacy ABI kept as a thin libm wrapper for any external callers. */
    private const ABI_ASINH = 'phpc_asinh';

    private const BRIDGE_ENTRY = 'asinh_libm_f64_entry';

    public static function ensureLinked(Context $context): void
    {
        self::libcAsinhDecl($context);
        self::ensurePhpcAsinhBridge($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $num): Value
    {
        return $context->builder->call(self::libcAsinhDecl($context), $num);
    }

    private static function libcAsinhDecl(Context $context): LlvmFunction
    {
        $func = $context->module->getNamedFunction(self::LIBC_ASINH);
        if (null !== $func) {
            $context->registerFunction(self::LIBC_ASINH, $func);

            return $func;
        }
        $double = $context->getTypeFromString('double');
        $fn = $context->module->addFunction(
            self::LIBC_ASINH,
            $context->context->functionType($double, false, $double)
        );
        $context->registerFunction(self::LIBC_ASINH, $fn);

        return $fn;
    }

    /**
     * Define {@code phpc_asinh} → libm {@code asinh} when missing. Skip if a prior
     * NestedJIT bridge already filled the symbol (cannot replace LLVM bodies);
     * {@see invoke} never calls that stale path.
     */
    private static function ensurePhpcAsinhBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_ASINH);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_ASINH, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $double = $context->getTypeFromString('double');
        $fn = $probe;
        if (null === $fn) {
            $fn = $context->module->addFunction(
                self::ABI_ASINH,
                $context->context->functionType($double, false, $double)
            );
        }
        $entry = $fn->appendBasicBlock(self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(
            $context->builder->call(self::libcAsinhDecl($context), $fn->getParam(0))
        );
        $context->registerFunction(self::ABI_ASINH, $fn);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
