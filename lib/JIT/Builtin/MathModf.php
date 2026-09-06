<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for modf via libm {@code modf(3)} (#36386).
 *
 * Peer of {@see MathFrexp} / {@see MathLdexp}: avoid NestedJIT helper objects on
 * the AOT hot path. php-src {@code ext/standard/math.c} historically exposed
 * {@code PHP_FUNCTION(modf)} → C {@code modf}; userland is absent from
 * math.stub.php (#25359) so this bridge stays internal/helper use only.
 * LLVM 9 has no modf intrinsic, so declare the libc symbol the linker already
 * pulls via {@code -lm} ({@code double modf(double, double*)} — int-part out
 * is f64). The PHP NestedJIT-safe trunc peel remains for NestedJIT-safe
 * reference only.
 */
final class MathModf
{
    /** Libc modf(3) — no LLVM 9 intrinsic. */
    private const LIBC_MODF = 'modf';

    /** Legacy ABI: fraction return + optional {@code __value__*} integer-part out. */
    private const ABI_MODF = 'phpc_modf';

    private const BRIDGE_ENTRY = 'modf_libm_f64_entry';

    public static function ensureLinked(Context $context): void
    {
        self::libcModfDecl($context);
        self::ensurePhpcModfBridge($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $num, Value $outPtr): Value
    {
        self::libcModfDecl($context);

        return self::emitLibmModf($context, $num, $outPtr);
    }

    /**
     * Emit libm modf at the current insert point: always write the integer part
     * to a local {@code double} slot, then optionally store it into {@code $outPtr}.
     * Leaves the builder positioned in the join block after the optional write.
     */
    private static function emitLibmModf(Context $context, Value $num, Value $outPtr): Value
    {
        $double = $context->getTypeFromString('double');
        $iptr = $context->builder->alloca($double, 1, 'modf_libm_iptr');
        $frac = $context->builder->call(self::libcModfDecl($context), $num, $iptr);

        $fn = BasicBlockHelper::parentFunction($context);
        $write = $fn->appendBasicBlock('modf_libm_write_iptr');
        $done = $fn->appendBasicBlock('modf_libm_done');

        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $outPtr,
            $outPtr->typeOf()->constNull()
        );
        $context->builder->branchIf($isNull, $done, $write);

        $context->builder->positionAtEnd($write);
        $intPart = $context->builder->load($iptr);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $outPtr,
            $intPart
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return $frac;
    }

    private static function libcModfDecl(Context $context): LlvmFunction
    {
        $func = $context->module->getNamedFunction(self::LIBC_MODF);
        if (null !== $func) {
            $context->registerFunction(self::LIBC_MODF, $func);

            return $func;
        }
        $double = $context->getTypeFromString('double');
        $doublep = $context->getTypeFromString('double*');
        $fn = $context->module->addFunction(
            self::LIBC_MODF,
            $context->context->functionType($double, false, $double, $doublep)
        );
        $context->registerFunction(self::LIBC_MODF, $fn);

        return $fn;
    }

    /**
     * Define {@code phpc_modf} → libm {@code modf} when missing. Skip if a prior
     * NestedJIT bridge already filled the symbol (cannot replace LLVM bodies);
     * {@see invoke} never calls that stale path.
     */
    private static function ensurePhpcModfBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_MODF);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_MODF, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $valuePtr = $context->getTypeFromString('__value__*');
        $double = $context->getTypeFromString('double');
        $fn = $probe;
        if (null === $fn) {
            $fn = $context->module->addFunction(
                self::ABI_MODF,
                $context->context->functionType($double, false, $double, $valuePtr)
            );
        }
        $entry = $fn->appendBasicBlock(self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $frac = self::emitLibmModf($context, $fn->getParam(0), $fn->getParam(1));
        $context->builder->returnValue($frac);
        $context->registerFunction(self::ABI_MODF, $fn);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
