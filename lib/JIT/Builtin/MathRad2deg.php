<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * JIT/AOT link for rad2deg() via inline {@code fmul} by {@code 180/M_PI} (#36386).
 *
 * Peer of {@see MathDeg2rad} / {@see MathAtan2}: avoid NestedJIT helper objects on
 * the AOT hot path. php-src {@code ext/standard/math.c} {@code PHP_FUNCTION(rad2deg)}
 * → {@code (num / M_PI) * 180.0}. One float multiply; no libm call.
 * The PHP helper remains for NestedJIT-safe reference only.
 */
final class MathRad2deg
{
    /** Legacy ABI kept as a thin fmul wrapper for any external callers. */
    private const ABI_RAD2DEG = 'phpc_rad2deg';

    private const BRIDGE_ENTRY = 'rad2deg_fmul_f64_entry';

    public static function ensureLinked(Context $context): void
    {
        self::ensurePhpcRad2degBridge($context);
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
            $double->constReal(180.0 / \M_PI)
        );
    }

    /**
     * Define {@code phpc_rad2deg} → inline fmul when missing. Skip if a prior
     * NestedJIT bridge already filled the symbol (cannot replace LLVM bodies);
     * {@see invoke} never calls that stale path.
     */
    private static function ensurePhpcRad2degBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_RAD2DEG);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_RAD2DEG, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $double = $context->getTypeFromString('double');
        $fn = $probe;
        if (null === $fn) {
            $fn = $context->module->addFunction(
                self::ABI_RAD2DEG,
                $context->context->functionType($double, false, $double)
            );
        }
        $entry = $fn->appendBasicBlock(self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(
            $context->builder->fmul(
                $fn->getParam(0),
                $double->constReal(180.0 / \M_PI)
            )
        );
        $context->registerFunction(self::ABI_RAD2DEG, $fn);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
