<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for sinh() via libm {@code sinh(3)} (#36386).
 *
 * Peer of {@see MathTan} / {@see MathAsin}: avoid NestedJIT helper objects on
 * the AOT hot path. php-src {@code ext/standard/math.c} {@code PHP_FUNCTION(sinh)}
 * → C {@code sinh}. LLVM 9 has no sinh intrinsic (unlike sin/cos/exp/log),
 * so declare the libc symbol the linker already pulls via {@code -lm}
 * ({@see NumberFormatRuntime} {@code floor} shape / {@see MathTan}).
 * The PHP fdlibm helper remains for NestedJIT-safe reference only.
 */
final class MathSinh
{
    /** Libc sinh(3) — no LLVM 9 intrinsic peer of sin/cos. */
    private const LIBC_SINH = 'sinh';

    /** Legacy ABI kept as a thin libm wrapper for any external callers. */
    private const ABI_SINH = 'phpc_sinh';

    private const BRIDGE_ENTRY = 'sinh_libm_f64_entry';

    public static function ensureLinked(Context $context): void
    {
        self::libcSinhDecl($context);
        self::ensurePhpcSinhBridge($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $num): Value
    {
        return $context->builder->call(self::libcSinhDecl($context), $num);
    }

    private static function libcSinhDecl(Context $context): LlvmFunction
    {
        $func = $context->module->getNamedFunction(self::LIBC_SINH);
        if (null !== $func) {
            $context->registerFunction(self::LIBC_SINH, $func);

            return $func;
        }
        $double = $context->getTypeFromString('double');
        $fn = $context->module->addFunction(
            self::LIBC_SINH,
            $context->context->functionType($double, false, $double)
        );
        $context->registerFunction(self::LIBC_SINH, $fn);

        return $fn;
    }

    /**
     * Define {@code phpc_sinh} → libm {@code sinh} when missing. Skip if a prior
     * NestedJIT bridge already filled the symbol (cannot replace LLVM bodies);
     * {@see invoke} never calls that stale path.
     */
    private static function ensurePhpcSinhBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_SINH);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_SINH, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $double = $context->getTypeFromString('double');
        $fn = $probe;
        if (null === $fn) {
            $fn = $context->module->addFunction(
                self::ABI_SINH,
                $context->context->functionType($double, false, $double)
            );
        }
        $entry = $fn->appendBasicBlock(self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(
            $context->builder->call(self::libcSinhDecl($context), $fn->getParam(0))
        );
        $context->registerFunction(self::ABI_SINH, $fn);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
