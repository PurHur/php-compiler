<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitRoundModeArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helper for round() (int/float, precision, PHP_ROUND_* mode).
 */
final class JitRound
{
    public static function round(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('round() requires one to three arguments');
        }

        $number = self::coerceDouble($context, $args[0]);
        $precision = $argc >= 2
            ? self::lowerPrecisionArg($context, $args[1])
            : $context->getTypeFromString('int64')->constInt(0, false);
        $mode = 3 === $argc
            ? JitRoundModeArg::lower($context, $args[2], 'round')
            : $context->getTypeFromString('int64')->constInt(StdlibConstants::PHP_ROUND_HALF_UP, false);

        return JitRoundLowering::lower($context, $number, $precision, $mode);
    }

    public static function roundWithModeInt(
        Context $context,
        JITVariable $num,
        ?JITVariable $precision,
        int $mode
    ): Value {
        $number = self::coerceDouble($context, $num);
        $prec = null !== $precision
            ? self::lowerPrecisionArg($context, $precision)
            : $context->getTypeFromString('int64')->constInt(0, false);
        $modeVal = $context->getTypeFromString('int64')->constInt($mode, false);

        return JitRoundLowering::lower($context, $number, $prec, $modeVal);
    }

    private static function lowerPrecisionArg(Context $context, JITVariable $arg): Value
    {
        JitInternalStrictArg::requireInt($context, $arg, 'round', 'precision', 2);

        return JitIntdiv::lowerIntBuiltinArg($context, $arg, 'round', 2, 'precision');
    }

    private static function coerceDouble(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_NATIVE_DOUBLE === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            $value = $context->helper->loadValue($arg);
            $f64 = $context->getTypeFromString('double');

            return $context->builder->sitofp($value, $f64);
        }

        return JitMathNumberArg::lowerToDouble($context, $arg, 'round', 1, 'num');
    }
}
