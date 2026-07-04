<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\JitIntdiv;
use PHPCompiler\ext\standard\JitRoundModeResolve;
use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\JIT\Builtin\RoundingModeJit;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPLLVM\Value;

/**
 * Lower round() mode parameter (int legacy + RoundingMode enum, #5934).
 */
final class JitRoundModeArg
{
    public static function lower(Context $context, Variable $arg, string $fn, string $paramName = 'mode'): Value
    {
        $compileTime = RoundingModeJit::compileTimeRoundMode($context, $arg)
            ?? JitRoundModeResolve::tryResolveMode($context, $arg, $context->jitEnclosingBlock);
        if (null !== $compileTime) {
            return $context->getTypeFromString('int64')->constInt($compileTime, false);
        }

        if (Variable::TYPE_NATIVE_LONG === $arg->type) {
            return $context->helper->loadValue($arg);
        }

        if (Variable::TYPE_OBJECT === $arg->type) {
            self::emitTypeErrorAndAbort($context, $fn, 'object', $paramName);

            return $context->getTypeFromString('int64')->constInt(StdlibConstants::PHP_ROUND_HALF_UP, false);
        }

        return JitIntdiv::lowerIntBuiltinArg($context, $arg, $fn, 3, $paramName);
    }

    private static function emitTypeErrorAndAbort(
        Context $context,
        string $fn,
        string $given,
        string $paramName = 'mode'
    ): void {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise(
            $context,
            sprintf(
                '%s(): Argument #3 ($%s) must be of type RoundingMode|int, %s given',
                $fn,
                $paramName,
                $given
            )
        );
        $context->builder->call($context->lookupFunction('abort'));
    }
}
