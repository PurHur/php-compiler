<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\ConvertCyrString;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for convert_cyr_string() — ConvertCyrStringJitHelper in-module (#4649). */
final class JitConvertCyrString
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (3 !== $argc) {
            throw new \ArgumentCountError(
                \sprintf('convert_cyr_string() expects exactly 3 arguments, %d given', $argc)
            );
        }

        $strLit = $args[0]->compileTimeString ?? null;
        $fromLit = $args[1]->compileTimeString ?? null;
        $toLit = $args[2]->compileTimeString ?? null;
        if (null !== $strLit && null !== $fromLit && null !== $toLit) {
            return self::materializeString(
                $context,
                VmConvertCyrString::convert($strLit, $fromLit, $toLit)
            );
        }

        $str = JitStringBuiltinArg::lower($context, $args[0], 'convert_cyr_string', 0, 'str');
        $from = JitStringBuiltinArg::lower($context, $args[1], 'convert_cyr_string', 1, 'from');
        $to = JitStringBuiltinArg::lower($context, $args[2], 'convert_cyr_string', 2, 'to');

        ConvertCyrString::ensureLinked($context);
        $resultStr = $context->builder->call(
            ConvertCyrString::helperFunction($context),
            $str,
            $from,
            $to
        );
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $resultStr);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }

    private static function materializeString(Context $context, string $str): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $context->builder->load($context->constantStringFromString($str))
        );
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }
}
