<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for ldexp via libm {@code ldexp(3)} (#36386).
 *
 * Peer of {@see MathAtan2} / {@see MathNextafter}: avoid NestedJIT helper objects on
 * the AOT hot path. php-src {@code ext/standard/math.c} historically exposed
 * {@code PHP_FUNCTION(ldexp)} → C {@code ldexp}; userland is absent from
 * math.stub.php (#24607) so this bridge stays internal/helper use only.
 * LLVM 9 has no ldexp intrinsic, so declare the libc symbol the linker already
 * pulls via {@code -lm} ({@see MathAtan2} two-arg libm shape; second arg is
 * {@code int} / i32).
 * The PHP NestedJIT-safe ×2/÷2 peel remains for NestedJIT-safe reference only.
 */
final class MathLdexp
{
    /** Libc ldexp(3) — no LLVM 9 intrinsic. */
    private const LIBC_LDEXP = 'ldexp';

    /** Legacy ABI kept as a thin libm wrapper for any external callers. */
    private const ABI_LDEXP = 'phpc_ldexp';

    private const BRIDGE_ENTRY = 'ldexp_libm_f64_entry';

    public static function ensureLinked(Context $context): void
    {
        self::libcLdexpDecl($context);
        self::ensurePhpcLdexpBridge($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $num, Value $exp): Value
    {
        return $context->builder->call(self::libcLdexpDecl($context), $num, $exp);
    }

    private static function libcLdexpDecl(Context $context): LlvmFunction
    {
        $func = $context->module->getNamedFunction(self::LIBC_LDEXP);
        if (null !== $func) {
            $context->registerFunction(self::LIBC_LDEXP, $func);

            return $func;
        }
        $double = $context->getTypeFromString('double');
        $i32 = $context->getTypeFromString('int32');
        $fn = $context->module->addFunction(
            self::LIBC_LDEXP,
            $context->context->functionType($double, false, $double, $i32)
        );
        $context->registerFunction(self::LIBC_LDEXP, $fn);

        return $fn;
    }

    /**
     * Define {@code phpc_ldexp} → libm {@code ldexp} when missing. Skip if a prior
     * NestedJIT bridge already filled the symbol (cannot replace LLVM bodies);
     * {@see invoke} never calls that stale path.
     */
    private static function ensurePhpcLdexpBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_LDEXP);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_LDEXP, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $double = $context->getTypeFromString('double');
        $i32 = $context->getTypeFromString('int32');
        $fn = $probe;
        if (null === $fn) {
            $fn = $context->module->addFunction(
                self::ABI_LDEXP,
                $context->context->functionType($double, false, $double, $i32)
            );
        }
        $entry = $fn->appendBasicBlock(self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(
            $context->builder->call(
                self::libcLdexpDecl($context),
                $fn->getParam(0),
                $fn->getParam(1)
            )
        );
        $context->registerFunction(self::ABI_LDEXP, $fn);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
