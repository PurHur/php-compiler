<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** assert_options() JIT/AOT lowering — {@see \PHPCompiler\JIT\Builtin\AssertOptionsRuntime} (#3316). */
final class JitAssertOptions
{
    private static int $guardSeq = 0;

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
        // Catchable ValueError must be emitted in the caller function (#30524) — not inside
        // __compiler_assert_options, where try handlers are out of scope for AOT.
        self::emitValidOptionGuard($context, $what);
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

    /**
     * php-src assert.c — unknown $option → zend_argument_value_error (#30524).
     * Recognized selectors: ASSERT_ACTIVE|CALLBACK|BAIL|WARNING|EXCEPTION (1..5).
     */
    private static function emitValidOptionGuard(Context $context, Value $what): void
    {
        $tag = 'aog'.(string) ++self::$guardSeq;
        $i64 = $context->getTypeFromString('int64');
        $isValid = $context->builder->icmp(
            Builder::INT_EQ,
            $what,
            $i64->constInt(StdlibConstants::ASSERT_ACTIVE, false)
        );
        foreach ([
            StdlibConstants::ASSERT_CALLBACK,
            StdlibConstants::ASSERT_BAIL,
            StdlibConstants::ASSERT_WARNING,
            StdlibConstants::ASSERT_EXCEPTION,
        ] as $const) {
            $isValid = $context->builder->or(
                $isValid,
                $context->builder->icmp(
                    Builder::INT_EQ,
                    $what,
                    $i64->constInt($const, false)
                )
            );
        }

        $okBb = BasicBlockHelper::append($context, 'assert_options_option_ok_'.$tag);
        $errBb = BasicBlockHelper::append($context, 'assert_options_option_err_'.$tag);
        $context->builder->branchIf($isValid, $okBb, $errBb);
        $context->builder->positionAtEnd($errBb);
        ExceptionBridge::emitValueErrorAndAbort($context, AssertOptionsJitHelper::MSG_INVALID_OPTION);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'assert_options_option_err_dead_'.$tag);
        $context->builder->positionAtEnd($okBb);
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
