<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for stream_context_create() (#1377, #2457). */
final class JitStreamContextCreate
{
    /** @return Value */
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 2) {
            throw new \LogicException(
                'stream_context_create() accepts at most two arguments in this compiler build'
            );
        }
        if (2 === $argc) {
            throw new \LogicException(
                'stream_context_create() second argument is not supported for JIT in this compiler build (issue #1377)'
            );
        }

        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $placeholder = new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::KIND_VALUE,
            $context->getTypeFromString('int64')->constInt(0, false)
        );

        $ht = 0 === $argc
            ? HashTableHelper::buildArrayFill($context, $zero, $zero, $placeholder)
            : self::loadArray($context, $args[0]);

        return $context->builder->call(
            $context->lookupFunction('__phpc_stream_context_attach_marker'),
            $ht
        );
    }

    private static function loadArray(Context $context, JITVariable $arg): Value
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
                JitValueBox::pointer($context, $arg->value)
            );
        }

        throw new \LogicException(
            'stream_context_create() argument #1 must be an array in this compiler build'
        );
    }
}
