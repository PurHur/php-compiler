<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for acosh() via libm {@code acosh(3)} (#36386).
 *
 * Peer of {@see MathAsinh} / {@see MathCosh}: avoid NestedJIT helper objects on
 * the AOT hot path. php-src {@code ext/standard/math.c} {@code PHP_FUNCTION(acosh)}
 * → C {@code acosh}. LLVM 9 has no acosh intrinsic (unlike sin/cos/exp/log),
 * so declare the libc symbol the linker already pulls via {@code -lm}
 * ({@see NumberFormatRuntime} {@code floor} shape / {@see MathAsinh}).
 * The PHP fdlibm helper remains for NestedJIT-safe reference only.
 */
final class MathAcosh
{
    /** Libc acosh(3) — no LLVM 9 intrinsic peer of sin/cos. */
    private const LIBC_ACOSH = 'acosh';

    /** Legacy ABI kept as a thin libm wrapper for any external callers. */
    private const ABI_ACOSH = 'phpc_acosh';

    private const BRIDGE_ENTRY = 'acosh_libm_f64_entry';

    public static function ensureLinked(Context $context): void
    {
        self::libcAcoshDecl($context);
        self::ensurePhpcAcoshBridge($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $num): Value
    {
        return $context->builder->call(self::libcAcoshDecl($context), $num);
    }

    private static function libcAcoshDecl(Context $context): LlvmFunction
    {
        $func = $context->module->getNamedFunction(self::LIBC_ACOSH);
        if (null !== $func) {
            $context->registerFunction(self::LIBC_ACOSH, $func);

            return $func;
        }
        $double = $context->getTypeFromString('double');
        $fn = $context->module->addFunction(
            self::LIBC_ACOSH,
            $context->context->functionType($double, false, $double)
        );
        $context->registerFunction(self::LIBC_ACOSH, $fn);

        return $fn;
    }

    /**
     * Define {@code phpc_acosh} → libm {@code acosh} when missing. Skip if a prior
     * NestedJIT bridge already filled the symbol (cannot replace LLVM bodies);
     * {@see invoke} never calls that stale path.
     */
    private static function ensurePhpcAcoshBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_ACOSH);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_ACOSH, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $double = $context->getTypeFromString('double');
        $fn = $probe;
        if (null === $fn) {
            $fn = $context->module->addFunction(
                self::ABI_ACOSH,
                $context->context->functionType($double, false, $double)
            );
        }
        $entry = $fn->appendBasicBlock(self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(
            $context->builder->call(self::libcAcoshDecl($context), $fn->getParam(0))
        );
        $context->registerFunction(self::ABI_ACOSH, $fn);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
