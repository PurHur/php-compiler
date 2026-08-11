<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** assert_options() JIT/AOT lowering — {@see \PHPCompiler\JIT\Builtin\AssertOptionsRuntime} (#3316). */
final class JitAssertOptions
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                'assert_options() expects at least 1 argument, '.$argc.' given'
            );
        }

        // #[\Deprecated(since: '8.3')] — E_DEPRECATED under PROFILE≥8.3 (#29209).
        AssertDeprecation::emitJitAssertOptions($context);

        \PHPCompiler\JIT\Builtin\AssertOptionsRuntime::ensureLinked($context);

        $i32 = $context->getTypeFromString('int32');
        $what = JitSleep::zParamLong($context, $args[0], 'assert_options', 1, 'option');
        $hasValue = $i32->constInt(2 === $argc ? 1 : 0, false);

        $nullValue = $context->getTypeFromString('__value__*')->constNull();
        $valuePtr = $nullValue;
        if (2 === $argc) {
            $valuePtr = self::valuePtrForSetArg($context, $args[1]);
        }

        $outSlot = JitValueBox::alloc($context);
        $outPtr = JitValueBox::pointer($context, $outSlot);
        $context->builder->call(
            $context->lookupFunction('__compiler_assert_options'),
            $hasValue,
            $what,
            $valuePtr,
            $outPtr
        );

        return $outPtr;
    }

    private static function valuePtrForSetArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return JitValueBox::valuePtrFromVariable($context, $arg);
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::assignToPointer($context, $ptr, $arg);

        return $ptr;
    }
}
