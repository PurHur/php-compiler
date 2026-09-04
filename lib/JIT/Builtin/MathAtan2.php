<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for atan2() via libm {@code atan2(3)} (#36386).
 *
 * Peer of {@see MathHypot} / {@see MathFmod}: avoid NestedJIT helper objects on
 * the AOT hot path. php-src {@code ext/standard/math.c} {@code PHP_FUNCTION(atan2)}
 * → C {@code atan2}. LLVM 9 has no atan2 intrinsic, so declare the libc symbol
 * the linker already pulls via {@code -lm}
 * ({@see NumberFormatRuntime} {@code floor} shape / {@see MathFpow} two-arg).
 * The PHP Taylor + quadrant helper remains for NestedJIT-safe reference only.
 */
final class MathAtan2
{
    /** Libc atan2(3) — no LLVM 9 intrinsic. */
    private const LIBC_ATAN2 = 'atan2';

    /** Legacy ABI kept as a thin libm wrapper for any external callers. */
    private const ABI_ATAN2 = 'phpc_atan2';

    private const BRIDGE_ENTRY = 'atan2_libm_f64_entry';

    public static function ensureLinked(Context $context): void
    {
        self::libcAtan2Decl($context);
        self::ensurePhpcAtan2Bridge($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $y, Value $x): Value
    {
        return $context->builder->call(self::libcAtan2Decl($context), $y, $x);
    }

    private static function libcAtan2Decl(Context $context): LlvmFunction
    {
        $func = $context->module->getNamedFunction(self::LIBC_ATAN2);
        if (null !== $func) {
            $context->registerFunction(self::LIBC_ATAN2, $func);

            return $func;
        }
        $double = $context->getTypeFromString('double');
        $fn = $context->module->addFunction(
            self::LIBC_ATAN2,
            $context->context->functionType($double, false, $double, $double)
        );
        $context->registerFunction(self::LIBC_ATAN2, $fn);

        return $fn;
    }

    /**
     * Define {@code phpc_atan2} → libm {@code atan2} when missing. Skip if a prior
     * NestedJIT bridge already filled the symbol (cannot replace LLVM bodies);
     * {@see invoke} never calls that stale path.
     */
    private static function ensurePhpcAtan2Bridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_ATAN2);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_ATAN2, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $double = $context->getTypeFromString('double');
        $fn = $probe;
        if (null === $fn) {
            $fn = $context->module->addFunction(
                self::ABI_ATAN2,
                $context->context->functionType($double, false, $double, $double)
            );
        }
        $entry = $fn->appendBasicBlock(self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(
            $context->builder->call(
                self::libcAtan2Decl($context),
                $fn->getParam(0),
                $fn->getParam(1)
            )
        );
        $context->registerFunction(self::ABI_ATAN2, $fn);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
