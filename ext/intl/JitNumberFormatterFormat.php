<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\JitMathNumberArg;
use PHPCompiler\JIT\Builtin\NumberFormatterFormatRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for NumberFormatter::format() / numfmt_format() via NumberFormatterFormatRuntime (#28648).
 *
 * Boxes {@see NumberFormatterFormatJitHelper::formatDecimalArgv} `__string__*` into `__value__*` string.
 * Receiver / style state is not read yet (Done-when DECIMAL default; peer finfo::buffer #28660).
 *
 * php-src: ext/intl/formatter/formatter_main.c — zim_NumberFormatter_format / PHP_FUNCTION(numfmt_format)
 */
final class JitNumberFormatterFormat
{
    /**
     * @param list<JITVariable> $args numfmt_format($formatter, $num, $type = TYPE_DEFAULT)
     */
    public static function invokeProcedural(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'numfmt_format() expects between 2 and 3 arguments, %d given',
                $argc
            ));
        }

        return self::invokeNum($context, $args[1], 'numfmt_format', 1, 'num');
    }

    /**
     * @param list<JITVariable> $args NumberFormatter::format($num, …) — $this first
     */
    public static function invokeMethod(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'NumberFormatter::format() expects between 1 and 2 arguments, %d given',
                \max(0, $argc - 1)
            ));
        }

        return self::invokeNum($context, $args[1], 'NumberFormatter::format', 1, 'num');
    }

    private static function invokeNum(
        Context $context,
        JITVariable $numArg,
        string $function,
        int $argIndex,
        string $paramName
    ): Value {
        $asFloat = JitMathNumberArg::lowerToDouble($context, $numArg, $function, $argIndex, $paramName);
        $raw = NumberFormatterFormatRuntime::invoke($context, $asFloat);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $raw
        );

        return $ptr;
    }
}
