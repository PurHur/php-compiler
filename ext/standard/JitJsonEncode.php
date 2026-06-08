<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT json_encode() lowering via VmJsonFormat LLVM helpers (#6852).
 */
final class JitJsonEncode
{
    public static function encode(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_HASHTABLE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__compiler_json_encode_array'),
                $context->helper->loadValue($arg)
            );
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__compiler_json_encode_value'),
                $context->helper->loadValue($arg)
            );
        }

        throw new \LogicException('json_encode() only supports arrays in this compiler build');
    }
}
