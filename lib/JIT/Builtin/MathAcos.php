<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for acos() via libm {@code acos(3)} (#36386).
 *
 * Peer of {@see MathAsin} / {@see MathAtan}: avoid NestedJIT helper objects on
 * the AOT hot path. php-src {@code ext/standard/math.c} {@code PHP_FUNCTION(acos)}
 * → C {@code acos}. LLVM 9 has no acos intrinsic (unlike sin/cos/exp/log),
 * so declare the libc symbol the linker already pulls via {@code -lm}
 * ({@see NumberFormatRuntime} {@code floor} shape / {@see MathAsin}).
 * The PHP fdlibm helper remains for NestedJIT-safe reference only.
 */
final class MathAcos
{
    /** Libc acos(3) — no LLVM 9 intrinsic peer of sin/cos. */
    private const LIBC_ACOS = 'acos';

    /** Legacy ABI kept as a thin libm wrapper for any external callers. */
    private const ABI_ACOS = 'phpc_acos';

    private const BRIDGE_ENTRY = 'acos_libm_f64_entry';

    public static function ensureLinked(Context $context): void
    {
        self::libcAcosDecl($context);
        self::ensurePhpcAcosBridge($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $num): Value
    {
        return $context->builder->call(self::libcAcosDecl($context), $num);
    }

    private static function libcAcosDecl(Context $context): LlvmFunction
    {
        $func = $context->module->getNamedFunction(self::LIBC_ACOS);
        if (null !== $func) {
            $context->registerFunction(self::LIBC_ACOS, $func);

            return $func;
        }
        $double = $context->getTypeFromString('double');
        $fn = $context->module->addFunction(
            self::LIBC_ACOS,
            $context->context->functionType($double, false, $double)
        );
        $context->registerFunction(self::LIBC_ACOS, $fn);

        return $fn;
    }

    /**
     * Define {@code phpc_acos} → libm {@code acos} when missing. Skip if a prior
     * NestedJIT bridge already filled the symbol (cannot replace LLVM bodies);
     * {@see invoke} never calls that stale path.
     */
    private static function ensurePhpcAcosBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_ACOS);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_ACOS, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $double = $context->getTypeFromString('double');
        $fn = $probe;
        if (null === $fn) {
            $fn = $context->module->addFunction(
                self::ABI_ACOS,
                $context->context->functionType($double, false, $double)
            );
        }
        $entry = $fn->appendBasicBlock(self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(
            $context->builder->call(self::libcAcosDecl($context), $fn->getParam(0))
        );
        $context->registerFunction(self::ABI_ACOS, $fn);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
