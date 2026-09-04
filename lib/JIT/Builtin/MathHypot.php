<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for hypot() via libm {@code hypot(3)} (#36386).
 *
 * Peer of {@see MathFmod} / {@see MathExpm1}: avoid NestedJIT helper objects on
 * the AOT hot path. php-src {@code ext/standard/math.c} {@code PHP_FUNCTION(hypot)}
 * → C {@code hypot}. LLVM 9 has no hypot intrinsic, so declare the libc symbol
 * the linker already pulls via {@code -lm}
 * ({@see NumberFormatRuntime} {@code floor} shape / {@see MathFpow} two-arg).
 * The PHP scale+sqrt helper remains for NestedJIT-safe reference only.
 */
final class MathHypot
{
    /** Libc hypot(3) — no LLVM 9 intrinsic. */
    private const LIBC_HYPOT = 'hypot';

    /** Legacy ABI kept as a thin libm wrapper for any external callers. */
    private const ABI_HYPOT = 'phpc_hypot';

    private const BRIDGE_ENTRY = 'hypot_libm_f64_entry';

    public static function ensureLinked(Context $context): void
    {
        self::libcHypotDecl($context);
        self::ensurePhpcHypotBridge($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $x, Value $y): Value
    {
        return $context->builder->call(self::libcHypotDecl($context), $x, $y);
    }

    private static function libcHypotDecl(Context $context): LlvmFunction
    {
        $func = $context->module->getNamedFunction(self::LIBC_HYPOT);
        if (null !== $func) {
            $context->registerFunction(self::LIBC_HYPOT, $func);

            return $func;
        }
        $double = $context->getTypeFromString('double');
        $fn = $context->module->addFunction(
            self::LIBC_HYPOT,
            $context->context->functionType($double, false, $double, $double)
        );
        $context->registerFunction(self::LIBC_HYPOT, $fn);

        return $fn;
    }

    /**
     * Define {@code phpc_hypot} → libm {@code hypot} when missing. Skip if a prior
     * NestedJIT bridge already filled the symbol (cannot replace LLVM bodies);
     * {@see invoke} never calls that stale path.
     */
    private static function ensurePhpcHypotBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_HYPOT);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_HYPOT, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $double = $context->getTypeFromString('double');
        $fn = $probe;
        if (null === $fn) {
            $fn = $context->module->addFunction(
                self::ABI_HYPOT,
                $context->context->functionType($double, false, $double, $double)
            );
        }
        $entry = $fn->appendBasicBlock(self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(
            $context->builder->call(
                self::libcHypotDecl($context),
                $fn->getParam(0),
                $fn->getParam(1)
            )
        );
        $context->registerFunction(self::ABI_HYPOT, $fn);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
