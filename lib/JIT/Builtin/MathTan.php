<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for tan() via libm {@code tan(3)} (#36386).
 *
 * Peer of {@see MathSin} / {@see MathCos}: avoid NestedJIT helper objects on
 * the AOT hot path. php-src {@code ext/standard/math.c} {@code PHP_FUNCTION(tan)}
 * → C {@code tan}. LLVM 9 has no tan intrinsic (unlike sin/cos/exp/log),
 * so declare the libc symbol the linker already pulls via {@code -lm}
 * ({@see NumberFormatRuntime} {@code floor} shape).
 * The PHP Cody–Waite / Horner helper remains for NestedJIT-safe reference only.
 */
final class MathTan
{
    /** Libc tan(3) — no LLVM 9 intrinsic peer of sin/cos. */
    private const LIBC_TAN = 'tan';

    /** Legacy ABI kept as a thin libm wrapper for any external callers. */
    private const ABI_TAN = 'phpc_tan';

    private const BRIDGE_ENTRY = 'tan_libm_f64_entry';

    public static function ensureLinked(Context $context): void
    {
        self::libcTanDecl($context);
        self::ensurePhpcTanBridge($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $num): Value
    {
        return $context->builder->call(self::libcTanDecl($context), $num);
    }

    private static function libcTanDecl(Context $context): LlvmFunction
    {
        $func = $context->module->getNamedFunction(self::LIBC_TAN);
        if (null !== $func) {
            $context->registerFunction(self::LIBC_TAN, $func);

            return $func;
        }
        $double = $context->getTypeFromString('double');
        $fn = $context->module->addFunction(
            self::LIBC_TAN,
            $context->context->functionType($double, false, $double)
        );
        $context->registerFunction(self::LIBC_TAN, $fn);

        return $fn;
    }

    /**
     * Define {@code phpc_tan} → libm {@code tan} when missing. Skip if a prior
     * NestedJIT bridge already filled the symbol (cannot replace LLVM bodies);
     * {@see invoke} never calls that stale path.
     */
    private static function ensurePhpcTanBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_TAN);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_TAN, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $double = $context->getTypeFromString('double');
        $fn = $probe;
        if (null === $fn) {
            $fn = $context->module->addFunction(
                self::ABI_TAN,
                $context->context->functionType($double, false, $double)
            );
        }
        $entry = $fn->appendBasicBlock(self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(
            $context->builder->call(self::libcTanDecl($context), $fn->getParam(0))
        );
        $context->registerFunction(self::ABI_TAN, $fn);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
