<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for fmod() via libm {@code fmod(3)} (#36386).
 *
 * Peer of {@see MathHypot} / {@see MathExpm1}: avoid NestedJIT helper objects on
 * the AOT hot path. php-src {@code ext/standard/math.c} {@code PHP_FUNCTION(fmod)}
 * → C {@code fmod}. LLVM 9 has no fmod intrinsic, so declare the libc symbol
 * the linker already pulls via {@code -lm}
 * ({@see NumberFormatRuntime} {@code floor} shape / {@see MathFpow} two-arg).
 * The PHP trunc-via-(int) helper remains for NestedJIT-safe reference only.
 */
final class MathFmod
{
    /** Libc fmod(3) — no LLVM 9 intrinsic. */
    private const LIBC_FMOD = 'fmod';

    /** Legacy ABI kept as a thin libm wrapper for any external callers. */
    private const ABI_FMOD = 'phpc_fmod';

    private const BRIDGE_ENTRY = 'fmod_libm_f64_entry';

    public static function ensureLinked(Context $context): void
    {
        self::libcFmodDecl($context);
        self::ensurePhpcFmodBridge($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $num1, Value $num2): Value
    {
        return $context->builder->call(self::libcFmodDecl($context), $num1, $num2);
    }

    private static function libcFmodDecl(Context $context): LlvmFunction
    {
        $func = $context->module->getNamedFunction(self::LIBC_FMOD);
        if (null !== $func) {
            $context->registerFunction(self::LIBC_FMOD, $func);

            return $func;
        }
        $double = $context->getTypeFromString('double');
        $fn = $context->module->addFunction(
            self::LIBC_FMOD,
            $context->context->functionType($double, false, $double, $double)
        );
        $context->registerFunction(self::LIBC_FMOD, $fn);

        return $fn;
    }

    /**
     * Define {@code phpc_fmod} → libm {@code fmod} when missing. Skip if a prior
     * NestedJIT bridge already filled the symbol (cannot replace LLVM bodies);
     * {@see invoke} never calls that stale path.
     */
    private static function ensurePhpcFmodBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_FMOD);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_FMOD, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $double = $context->getTypeFromString('double');
        $fn = $probe;
        if (null === $fn) {
            $fn = $context->module->addFunction(
                self::ABI_FMOD,
                $context->context->functionType($double, false, $double, $double)
            );
        }
        $entry = $fn->appendBasicBlock(self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(
            $context->builder->call(
                self::libcFmodDecl($context),
                $fn->getParam(0),
                $fn->getParam(1)
            )
        );
        $context->registerFunction(self::ABI_FMOD, $fn);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
