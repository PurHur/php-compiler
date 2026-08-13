<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StreamContextRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for stream_context_get_params() (#3448). */
final class JitStreamContextGetParams
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        // Arity checked by stream_context_get_params::call via requireExactJitArgCount (#30584).
        StreamContextRuntime::ensureLinked($context);

        $ctxHt = self::loadContextArray($context, $args[0]);
        $paramsHt = $context->builder->call(
            $context->lookupFunction('__phpc_stream_context_get_params'),
            $ctxHt
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $paramsHt
        );

        return $ptr;
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
            'stream_context_get_params() argument #1 must be a stream context in this compiler build'
        );
    }
}
