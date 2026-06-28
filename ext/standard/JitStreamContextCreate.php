<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StreamContextRuntime;
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

        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        $nullHt = $htPtrTy->constNull();
        $optionsHt = $nullHt;
        if ($argc >= 1) {
            $optionsHt = self::loadArrayArg($context, $args[0], 1);
        }
        $paramsHt = $nullHt;
        if (2 === $argc) {
            $paramsHt = self::loadArrayArg($context, $args[1], 2);
        }

        StreamContextRuntime::ensureLinked($context);

        $ht = $context->builder->call(
            $context->lookupFunction('__phpc_stream_context_create'),
            $optionsHt,
            $paramsHt
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );

        return $ptr;
    }

    private static function loadArrayArg(Context $context, JITVariable $arg, int $position): Value
    {
        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            return $context->getTypeFromString('__hashtable__*')->constNull();
        }
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
            "stream_context_create() argument #{$position} must be an array in this compiler build"
        );
    }
}
