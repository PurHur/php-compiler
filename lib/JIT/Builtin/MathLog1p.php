<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for log1p() via libm {@code log1p(3)} (#36386).
 *
 * Peer of {@see MathLog} / {@see MathExpm1}: avoid NestedJIT helper objects on
 * the AOT hot path. php-src {@code ext/standard/math.c} {@code PHP_FUNCTION(log1p)}
 * → C {@code log1p}. LLVM 9 has no log1p intrinsic (unlike exp/log), so declare
 * the libc symbol the linker already pulls via {@code -lm}
 * ({@see NumberFormatRuntime} {@code floor} shape / {@see MathAsinh}).
 * The PHP fdlibm series helper remains for NestedJIT-safe reference only.
 */
final class MathLog1p
{
    /** Libc log1p(3) — no LLVM 9 intrinsic peer of exp/log. */
    private const LIBC_LOG1P = 'log1p';

    /** Legacy ABI kept as a thin libm wrapper for any external callers. */
    private const ABI_LOG1P = 'phpc_log1p';

    private const BRIDGE_ENTRY = 'log1p_libm_f64_entry';

    public static function ensureLinked(Context $context): void
    {
        self::libcLog1pDecl($context);
        self::ensurePhpcLog1pBridge($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $num): Value
    {
        return $context->builder->call(self::libcLog1pDecl($context), $num);
    }

    private static function libcLog1pDecl(Context $context): LlvmFunction
    {
        $func = $context->module->getNamedFunction(self::LIBC_LOG1P);
        if (null !== $func) {
            $context->registerFunction(self::LIBC_LOG1P, $func);

            return $func;
        }
        $double = $context->getTypeFromString('double');
        $fn = $context->module->addFunction(
            self::LIBC_LOG1P,
            $context->context->functionType($double, false, $double)
        );
        $context->registerFunction(self::LIBC_LOG1P, $fn);

        return $fn;
    }

    /**
     * Define {@code phpc_log1p} → libm {@code log1p} when missing. Skip if a prior
     * NestedJIT bridge already filled the symbol (cannot replace LLVM bodies);
     * {@see invoke} never calls that stale path.
     */
    private static function ensurePhpcLog1pBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_LOG1P);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_LOG1P, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $double = $context->getTypeFromString('double');
        $fn = $probe;
        if (null === $fn) {
            $fn = $context->module->addFunction(
                self::ABI_LOG1P,
                $context->context->functionType($double, false, $double)
            );
        }
        $entry = $fn->appendBasicBlock(self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(
            $context->builder->call(self::libcLog1pDecl($context), $fn->getParam(0))
        );
        $context->registerFunction(self::ABI_LOG1P, $fn);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
