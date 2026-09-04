<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for expm1() via libm {@code expm1(3)} (#36386).
 *
 * Peer of {@see MathExp} / {@see MathLog1p}: avoid NestedJIT helper objects on
 * the AOT hot path. php-src {@code ext/standard/math.c} {@code PHP_FUNCTION(expm1)}
 * → C {@code expm1}. LLVM 9 has no expm1 intrinsic (unlike exp/log), so declare
 * the libc symbol the linker already pulls via {@code -lm}
 * ({@see NumberFormatRuntime} {@code floor} shape / {@see MathAsinh}).
 * The PHP Taylor/reduction helper remains for NestedJIT-safe reference only.
 */
final class MathExpm1
{
    /** Libc expm1(3) — no LLVM 9 intrinsic peer of exp/log. */
    private const LIBC_EXPM1 = 'expm1';

    /** Legacy ABI kept as a thin libm wrapper for any external callers. */
    private const ABI_EXPM1 = 'phpc_expm1';

    private const BRIDGE_ENTRY = 'expm1_libm_f64_entry';

    public static function ensureLinked(Context $context): void
    {
        self::libcExpm1Decl($context);
        self::ensurePhpcExpm1Bridge($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $num): Value
    {
        return $context->builder->call(self::libcExpm1Decl($context), $num);
    }

    private static function libcExpm1Decl(Context $context): LlvmFunction
    {
        $func = $context->module->getNamedFunction(self::LIBC_EXPM1);
        if (null !== $func) {
            $context->registerFunction(self::LIBC_EXPM1, $func);

            return $func;
        }
        $double = $context->getTypeFromString('double');
        $fn = $context->module->addFunction(
            self::LIBC_EXPM1,
            $context->context->functionType($double, false, $double)
        );
        $context->registerFunction(self::LIBC_EXPM1, $fn);

        return $fn;
    }

    /**
     * Define {@code phpc_expm1} → libm {@code expm1} when missing. Skip if a prior
     * NestedJIT bridge already filled the symbol (cannot replace LLVM bodies);
     * {@see invoke} never calls that stale path.
     */
    private static function ensurePhpcExpm1Bridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_EXPM1);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_EXPM1, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $double = $context->getTypeFromString('double');
        $fn = $probe;
        if (null === $fn) {
            $fn = $context->module->addFunction(
                self::ABI_EXPM1,
                $context->context->functionType($double, false, $double)
            );
        }
        $entry = $fn->appendBasicBlock(self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(
            $context->builder->call(self::libcExpm1Decl($context), $fn->getParam(0))
        );
        $context->registerFunction(self::ABI_EXPM1, $fn);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
