<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for frexp via libm {@code frexp(3)} (#36386).
 *
 * Peer of {@see MathLdexp} / {@see MathNextafter}: avoid NestedJIT helper objects on
 * the AOT hot path. php-src {@code ext/standard/math.c} historically exposed
 * {@code PHP_FUNCTION(frexp)} → C {@code frexp}; userland is absent from
 * math.stub.php (#24133) so this bridge stays internal/helper use only.
 * LLVM 9 has no frexp intrinsic, so declare the libc symbol the linker already
 * pulls via {@code -lm} ({@code double frexp(double, int*)} — exp out is i32).
 * The PHP NestedJIT-safe ×2/÷2 peel remains for NestedJIT-safe reference only.
 */
final class MathFrexp
{
    /** Libc frexp(3) — no LLVM 9 intrinsic. */
    private const LIBC_FREXP = 'frexp';

    /** Legacy ABI: fraction return + optional {@code __value__*} exponent out. */
    private const ABI_FREXP = 'phpc_frexp';

    private const BRIDGE_ENTRY = 'frexp_libm_f64_entry';

    public static function ensureLinked(Context $context): void
    {
        self::libcFrexpDecl($context);
        self::ensurePhpcFrexpBridge($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $num, Value $outPtr): Value
    {
        self::libcFrexpDecl($context);

        return self::emitLibmFrexp($context, $num, $outPtr);
    }

    /**
     * Emit libm frexp at the current insert point: always write exp to a local
     * {@code int32} slot, then optionally store it into {@code $outPtr} as a long.
     * Leaves the builder positioned in the join block after the optional write.
     */
    private static function emitLibmFrexp(Context $context, Value $num, Value $outPtr): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $expSlot = $context->builder->alloca($i32, 1, 'frexp_libm_exp');
        $frac = $context->builder->call(self::libcFrexpDecl($context), $num, $expSlot);

        $fn = BasicBlockHelper::parentFunction($context);
        $write = $fn->appendBasicBlock('frexp_libm_write_exp');
        $done = $fn->appendBasicBlock('frexp_libm_done');

        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $outPtr,
            $outPtr->typeOf()->constNull()
        );
        $context->builder->branchIf($isNull, $done, $write);

        $context->builder->positionAtEnd($write);
        $expI32 = $context->builder->load($expSlot);
        $expI64 = $expI32->typeOf() === $i64
            ? $expI32
            : $context->builder->sext($expI32, $i64);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $outPtr,
            $expI64
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return $frac;
    }

    private static function libcFrexpDecl(Context $context): LlvmFunction
    {
        $func = $context->module->getNamedFunction(self::LIBC_FREXP);
        if (null !== $func) {
            $context->registerFunction(self::LIBC_FREXP, $func);

            return $func;
        }
        $double = $context->getTypeFromString('double');
        $i32p = $context->getTypeFromString('int32*');
        $fn = $context->module->addFunction(
            self::LIBC_FREXP,
            $context->context->functionType($double, false, $double, $i32p)
        );
        $context->registerFunction(self::LIBC_FREXP, $fn);

        return $fn;
    }

    /**
     * Define {@code phpc_frexp} → libm {@code frexp} when missing. Skip if a prior
     * NestedJIT bridge already filled the symbol (cannot replace LLVM bodies);
     * {@see invoke} never calls that stale path.
     */
    private static function ensurePhpcFrexpBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_FREXP);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_FREXP, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $valuePtr = $context->getTypeFromString('__value__*');
        $double = $context->getTypeFromString('double');
        $fn = $probe;
        if (null === $fn) {
            $fn = $context->module->addFunction(
                self::ABI_FREXP,
                $context->context->functionType($double, false, $double, $valuePtr)
            );
        }
        $entry = $fn->appendBasicBlock(self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $frac = self::emitLibmFrexp($context, $fn->getParam(0), $fn->getParam(1));
        $context->builder->returnValue($frac);
        $context->registerFunction(self::ABI_FREXP, $fn);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
