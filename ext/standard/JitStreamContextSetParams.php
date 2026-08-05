<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StreamContextRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for stream_context_set_params() (#6122, #8063). */
final class JitStreamContextSetParams
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException(
                'stream_context_set_params() requires exactly two arguments in this compiler build'
            );
        }

        StreamContextRuntime::ensureLinked($context);

        JitStreamContextRequiredArg::validate($context, $args[0], 'stream_context_set_params', 1);

        $ctxHt = self::loadContextArray($context, $args[0]);
        $paramsHt = self::loadParamsArray($context, $args[1]);

        $context->builder->call(
            $context->lookupFunction('__phpc_stream_context_set_params'),
            $ctxHt,
            $paramsHt
        );

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
            'stream_context_set_params() argument #1 must be a stream context in this compiler build'
        );
    }

    private static function loadParamsArray(Context $context, JITVariable $arg): Value
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
            'stream_context_set_params() argument #2 must be an array in this compiler build'
        );
    }
}
