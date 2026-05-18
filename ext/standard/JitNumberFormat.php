<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helper for number_format() (int/float, 0–4 args; subset of PHP).
 */
final class JitNumberFormat
{
    public static function format(Context $context, JITVariable ...$args): Value
    {
        $argc = count($args);
        if ($argc < 1 || $argc > 4) {
            throw new \LogicException('number_format() requires one to four arguments');
        }

        $number = self::coerceDouble($context, $args[0]);
        $decimals = $argc >= 2
            ? self::coerceInt64($context, $args[1])
            : $context->getTypeFromString('int64')->constInt(0, false);
        $decSep = $argc >= 3
            ? self::coerceString($context, $args[2])
            : $context->builder->load($context->constantStringFromString('.'));
        $thouSep = 4 === $argc
            ? self::coerceString($context, $args[3])
            : $context->builder->load($context->constantStringFromString(','));

        return $context->builder->call(
            $context->lookupFunction('__compiler_number_format'),
            $number,
            $decimals,
            $decSep,
            $thouSep
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
                throw new \LogicException(
                    'number_format() number must be an integer or float in this compiler build'
                );
        }
    }

    private static function coerceInt64(Context $context, JITVariable $arg): Value
    {
        $value = $context->helper->loadValue($arg);
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            return $value;
        }

        throw new \LogicException('number_format() decimals must be an integer in this compiler build');
    }

    private static function coerceString(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_STRING === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readString'),
                $arg->value
            );
        }

        throw new \LogicException('number_format() separator must be a string in this compiler build');
    }
}
