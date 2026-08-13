<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StreamContextRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for stream_context_set_option() (#3448). */
final class JitStreamContextSetOption
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        // Arity checked by stream_context_set_option::call via requireArgCountRangeJit (#30584).
        $argc = \count($args);

        StreamContextRuntime::ensureLinked($context);

        JitStreamContextRequiredArg::validate($context, $args[0], 'stream_context_set_option', 1);

        $ctxHt = self::loadContextArray($context, $args[0]);
        if (2 === $argc) {
            $optHt = self::loadOptionsArray($context, $args[1], 2);
            $context->builder->call(
                $context->lookupFunction('__phpc_stream_context_merge_options'),
                $ctxHt,
                $optHt
            );
        } else {
            // 3 or 4 args: singular form; omitted value is null (#30584 / php-src stub).
            $wrapperVal = self::loadValuePointer($context, $args[1], 2);
            $optionVal = self::loadValuePointer($context, $args[2], 3);
            if (4 === $argc) {
                $valueVal = self::loadValuePointer($context, $args[3], 4);
            } else {
                $nullSlot = JitValueBox::alloc($context);
                $valueVal = JitValueBox::pointer($context, $nullSlot);
                $context->builder->call($context->lookupFunction('__value__writeNull'), $valueVal);
            }
            $context->builder->call(
                $context->lookupFunction('__phpc_stream_context_set_single_option'),
                $ctxHt,
                $wrapperVal,
                $optionVal,
                $valueVal
            );
        }

        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(true));

        return JitValueBox::pointer($context, $slot);
    }

    private static function loadContextArray(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_HASHTABLE === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (0 !== ($arg->type & JITVariable::IS_NATIVE_ARRAY)) {
            return HashTableHelper::materializeNativeArrayForCall($context, $arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readHashtable'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \LogicException(
            'stream_context_set_option() argument #1 must be a stream context in this compiler build'
        );
    }

    private static function loadOptionsArray(Context $context, JITVariable $arg, int $position): Value
    {
        if (JITVariable::TYPE_HASHTABLE === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (0 !== ($arg->type & JITVariable::IS_NATIVE_ARRAY)) {
            return HashTableHelper::materializeNativeArrayForCall($context, $arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readHashtable'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \LogicException(
            "stream_context_set_option() argument #{$position} must be an array in this compiler build"
        );
    }

    private static function loadValuePointer(Context $context, JITVariable $arg, int $position): Value
    {
        // Thin AOT keeps string/scalar literals as native types; full JIT often boxes to TYPE_VALUE (#27295).
        if (
            JITVariable::TYPE_VALUE === $arg->type
            || JITVariable::TYPE_STRING === $arg->type
            || JITVariable::TYPE_NATIVE_LONG === $arg->type
            || JITVariable::TYPE_NATIVE_BOOL === $arg->type
            || JITVariable::TYPE_NATIVE_DOUBLE === $arg->type
            || JITVariable::TYPE_NULL === $arg->type
            || JITVariable::TYPE_OBJECT === $arg->type
            || JITVariable::TYPE_HASHTABLE === $arg->type
            || 0 !== ($arg->type & JITVariable::IS_NATIVE_ARRAY)
        ) {
            return JitValueBox::valuePtrFromVariable($context, $arg);
        }

        throw new \LogicException(
            "stream_context_set_option() argument #{$position} must be a value in this compiler build"
        );
    }
}
