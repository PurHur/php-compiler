<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for atan() via libm {@code atan(3)} (#36386).
 *
 * Peer of {@see MathTan}: avoid NestedJIT helper objects on the AOT hot path.
 * php-src {@code ext/standard/math.c} {@code PHP_FUNCTION(atan)} → C {@code atan}.
 * LLVM 9 has no atan intrinsic (unlike sin/cos/exp/log), so declare the libc
 * symbol the linker already pulls via {@code -lm}
 * ({@see NumberFormatRuntime} {@code floor} shape / {@see MathTan}).
 * The PHP fdlibm helper remains for NestedJIT-safe reference only.
 */
final class MathAtan
{
    /** Libc atan(3) — no LLVM 9 intrinsic peer of sin/cos. */
    private const LIBC_ATAN = 'atan';

    /** Legacy ABI kept as a thin libm wrapper for any external callers. */
    private const ABI_ATAN = 'phpc_atan';

    private const BRIDGE_ENTRY = 'atan_libm_f64_entry';

    public static function ensureLinked(Context $context): void
    {
        self::libcAtanDecl($context);
        self::ensurePhpcAtanBridge($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $num): Value
    {
        return $context->builder->call(self::libcAtanDecl($context), $num);
    }

    private static function libcAtanDecl(Context $context): LlvmFunction
    {
        $func = $context->module->getNamedFunction(self::LIBC_ATAN);
        if (null !== $func) {
            $context->registerFunction(self::LIBC_ATAN, $func);

            return $func;
        }
        $double = $context->getTypeFromString('double');
        $fn = $context->module->addFunction(
            self::LIBC_ATAN,
            $context->context->functionType($double, false, $double)
        );
        $context->registerFunction(self::LIBC_ATAN, $fn);

        return $fn;
    }

    /**
     * Define {@code phpc_atan} → libm {@code atan} when missing. Skip if a prior
     * NestedJIT bridge already filled the symbol (cannot replace LLVM bodies);
     * {@see invoke} never calls that stale path.
     */
    private static function ensurePhpcAtanBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_ATAN);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_ATAN, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $double = $context->getTypeFromString('double');
        $fn = $probe;
        if (null === $fn) {
            $fn = $context->module->addFunction(
                self::ABI_ATAN,
                $context->context->functionType($double, false, $double)
            );
        }
        $entry = $fn->appendBasicBlock(self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(
            $context->builder->call(self::libcAtanDecl($context), $fn->getParam(0))
        );
        $context->registerFunction(self::ABI_ATAN, $fn);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
