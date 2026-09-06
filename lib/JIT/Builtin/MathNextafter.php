<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for nextafter() via libm {@code nextafter(3)} (#36386).
 *
 * Peer of {@see MathAtan2} / {@see MathHypot}: avoid NestedJIT helper objects on
 * the AOT hot path. php-src {@code ext/standard/math.c} {@code PHP_FUNCTION(nextafter)}
 * → C {@code nextafter}. LLVM 9 has no nextafter intrinsic, so declare the libc
 * symbol the linker already pulls via {@code -lm}
 * ({@see MathAtan2} two-arg libm shape).
 * The PHP NestedJIT-safe ULP peel remains for NestedJIT-safe reference only.
 */
final class MathNextafter
{
    /** Libc nextafter(3) — no LLVM 9 intrinsic. */
    private const LIBC_NEXTAFTER = 'nextafter';

    /** Legacy ABI kept as a thin libm wrapper for any external callers. */
    private const ABI_NEXTAFTER = 'phpc_nextafter';

    private const BRIDGE_ENTRY = 'nextafter_libm_f64_entry';

    public static function ensureLinked(Context $context): void
    {
        self::libcNextafterDecl($context);
        self::ensurePhpcNextafterBridge($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $num, Value $next): Value
    {
        return $context->builder->call(self::libcNextafterDecl($context), $num, $next);
    }

    private static function libcNextafterDecl(Context $context): LlvmFunction
    {
        $func = $context->module->getNamedFunction(self::LIBC_NEXTAFTER);
        if (null !== $func) {
            $context->registerFunction(self::LIBC_NEXTAFTER, $func);

            return $func;
        }
        $double = $context->getTypeFromString('double');
        $fn = $context->module->addFunction(
            self::LIBC_NEXTAFTER,
            $context->context->functionType($double, false, $double, $double)
        );
        $context->registerFunction(self::LIBC_NEXTAFTER, $fn);

        return $fn;
    }

    /**
     * Define {@code phpc_nextafter} → libm {@code nextafter} when missing. Skip if a prior
     * NestedJIT bridge already filled the symbol (cannot replace LLVM bodies);
     * {@see invoke} never calls that stale path.
     */
    private static function ensurePhpcNextafterBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NEXTAFTER);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_NEXTAFTER, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $double = $context->getTypeFromString('double');
        $fn = $probe;
        if (null === $fn) {
            $fn = $context->module->addFunction(
                self::ABI_NEXTAFTER,
                $context->context->functionType($double, false, $double, $double)
            );
        }
        $entry = $fn->appendBasicBlock(self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(
            $context->builder->call(
                self::libcNextafterDecl($context),
                $fn->getParam(0),
                $fn->getParam(1)
            )
        );
        $context->registerFunction(self::ABI_NEXTAFTER, $fn);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
