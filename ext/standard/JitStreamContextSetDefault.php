<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StreamContextRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for stream_context_set_default() (#6367, #9340, #12895). */
final class JitStreamContextSetDefault
{
    private const SET_DEFAULT_HELPER = 'PHPCompiler\\ext\\standard\\StreamContextJitHelper::setDefault';

    /** @return Value */
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException(
                'stream_context_set_default() requires exactly one argument in this compiler build'
            );
        }

        $optionsHt = self::loadArrayArg($context, $args[0], 1);

        StreamContextRuntime::ensureLinked($context);

        $ht = $context->builder->call(
            StreamContextRuntime::helperFunction($context, self::SET_DEFAULT_HELPER),
            $optionsHt
        );

        return self::wrapHashtableResult($context, $ht);
    }

    private static function wrapHashtableResult(Context $context, Value $ht): Value
    {
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
            "stream_context_set_default() argument #{$position} must be an array in this compiler build"
        );
    }
}
