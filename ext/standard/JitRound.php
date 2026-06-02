<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
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
            ? self::coerceInt64($context, $args[1], 'round() precision')
            : $context->getTypeFromString('int64')->constInt(0, false);
        $mode = 3 === $argc
            ? self::coerceInt64($context, $args[2], 'round() mode')
            : $context->getTypeFromString('int64')->constInt(StdlibConstants::PHP_ROUND_HALF_UP, false);

        return $context->builder->call(
            $context->lookupFunction('__compiler_round'),
            $number,
            $precision,
            $mode
        );
    }

    private static function coerceDouble(Context $context, JITVariable $arg): Value
    {
        $value = $context->helper->loadValue($arg);
        $f64 = $context->getTypeFromString('double');
        switch ($arg->type) {
            case JITVariable::TYPE_NATIVE_LONG:
                return $context->builder->sitofp($value, $f64);
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return $value;
            default:
                throw new \LogicException('round() only supports integers and floats in this compiler build');
        }
    }

    private static function coerceInt64(Context $context, JITVariable $arg, string $label): Value
    {
        return JitLongArg::lower($context, $arg, $label);
    }
}
