<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringSerialize;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for serialize() via __compiler_serialize_value (issue #1174). */
final class JitSerialize
{
    public static function encode(Context $context, JITVariable $arg): Value
    {
        StringSerialize::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_serialize_value'),
            self::valuePointer($context, $arg)
        );
    }

    private static function valuePointer(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return JitValueBox::valuePtrFromVariable($context, $arg);
        }

        if (JITVariable::TYPE_HASHTABLE === $arg->type || 0 !== ($arg->type & JITVariable::IS_NATIVE_ARRAY)) {
            throw new \LogicException(
                'serialize() arrays are not supported by JIT/AOT in this compiler build; use VM or scalar values (issue #1174)'
            );
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $raw = $context->helper->loadValue($arg);

        switch ($arg->type) {
            case JITVariable::TYPE_NULL:
                $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

                return $ptr;
            case JITVariable::TYPE_NATIVE_BOOL:
                $context->builder->call(
                    $context->lookupFunction('__value__writeLong'),
                    $ptr,
                    $context->builder->zExt($raw, $context->getTypeFromString('int64'))
                );

                return $ptr;
            case JITVariable::TYPE_NATIVE_LONG:
                $context->builder->call($context->lookupFunction('__value__writeLong'), $ptr, $raw);

                return $ptr;
            case JITVariable::TYPE_NATIVE_DOUBLE:
                $context->builder->call($context->lookupFunction('__value__writeDouble'), $ptr, $raw);

                return $ptr;
            case JITVariable::TYPE_STRING:
                $owned = $context->builder->call($context->lookupFunction('__string__separate'), $raw);
                $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

                return $ptr;
        }

        throw new \LogicException('serialize() value type not supported in this compiler build');
    }
}
