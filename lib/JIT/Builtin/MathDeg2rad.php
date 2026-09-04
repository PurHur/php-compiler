<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * JIT/AOT link for deg2rad() via inline {@code fmul} by {@code M_PI/180} (#36386).
 *
 * Peer of {@see MathRad2deg} / {@see MathAtan2}: avoid NestedJIT helper objects on
 * the AOT hot path. php-src {@code ext/standard/math.c} {@code PHP_FUNCTION(deg2rad)}
 * → {@code (num / 180.0) * M_PI}. One float multiply; no libm call.
 * The PHP helper remains for NestedJIT-safe reference only.
 */
final class MathDeg2rad
{
    /** Legacy ABI kept as a thin fmul wrapper for any external callers. */
    private const ABI_DEG2RAD = 'phpc_deg2rad';

    private const BRIDGE_ENTRY = 'deg2rad_fmul_f64_entry';

    public static function ensureLinked(Context $context): void
    {
        self::ensurePhpcDeg2radBridge($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $num): Value
    {
        $double = $context->getTypeFromString('double');

        return $context->builder->fmul(
            $num,
            $double->constReal(\M_PI / 180.0)
        );
    }

    /**
     * Define {@code phpc_deg2rad} → inline fmul when missing. Skip if a prior
     * NestedJIT bridge already filled the symbol (cannot replace LLVM bodies);
     * {@see invoke} never calls that stale path.
     */
    private static function ensurePhpcDeg2radBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_DEG2RAD);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_DEG2RAD, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $double = $context->getTypeFromString('double');
        $fn = $probe;
        if (null === $fn) {
            $fn = $context->module->addFunction(
                self::ABI_DEG2RAD,
                $context->context->functionType($double, false, $double)
            );
        }
        $entry = $fn->appendBasicBlock(self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(
            $context->builder->fmul(
                $fn->getParam(0),
                $double->constReal(\M_PI / 180.0)
            )
        );
        $context->registerFunction(self::ABI_DEG2RAD, $fn);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
