<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for asin() via libm {@code asin(3)} (#36386).
 *
 * Peer of {@see MathAtan} / {@see MathTan}: avoid NestedJIT helper objects on
 * the AOT hot path. php-src {@code ext/standard/math.c} {@code PHP_FUNCTION(asin)}
 * → C {@code asin}. LLVM 9 has no asin intrinsic (unlike sin/cos/exp/log),
 * so declare the libc symbol the linker already pulls via {@code -lm}
 * ({@see NumberFormatRuntime} {@code floor} shape / {@see MathAtan}).
 * The PHP fdlibm helper remains for NestedJIT-safe reference only.
 */
final class MathAsin
{
    /** Libc asin(3) — no LLVM 9 intrinsic peer of sin/cos. */
    private const LIBC_ASIN = 'asin';

    /** Legacy ABI kept as a thin libm wrapper for any external callers. */
    private const ABI_ASIN = 'phpc_asin';

    private const BRIDGE_ENTRY = 'asin_libm_f64_entry';

    public static function ensureLinked(Context $context): void
    {
        self::libcAsinDecl($context);
        self::ensurePhpcAsinBridge($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $num): Value
    {
        return $context->builder->call(self::libcAsinDecl($context), $num);
    }

    private static function libcAsinDecl(Context $context): LlvmFunction
    {
        $func = $context->module->getNamedFunction(self::LIBC_ASIN);
        if (null !== $func) {
            $context->registerFunction(self::LIBC_ASIN, $func);

            return $func;
        }
        $double = $context->getTypeFromString('double');
        $fn = $context->module->addFunction(
            self::LIBC_ASIN,
            $context->context->functionType($double, false, $double)
        );
        $context->registerFunction(self::LIBC_ASIN, $fn);

        return $fn;
    }

    /**
     * Define {@code phpc_asin} → libm {@code asin} when missing. Skip if a prior
     * NestedJIT bridge already filled the symbol (cannot replace LLVM bodies);
     * {@see invoke} never calls that stale path.
     */
    private static function ensurePhpcAsinBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_ASIN);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_ASIN, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $double = $context->getTypeFromString('double');
        $fn = $probe;
        if (null === $fn) {
            $fn = $context->module->addFunction(
                self::ABI_ASIN,
                $context->context->functionType($double, false, $double)
            );
        }
        $entry = $fn->appendBasicBlock(self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(
            $context->builder->call(self::libcAsinDecl($context), $fn->getParam(0))
        );
        $context->registerFunction(self::ABI_ASIN, $fn);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
