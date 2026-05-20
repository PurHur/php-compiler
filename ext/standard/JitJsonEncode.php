<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

final class JitJsonEncode
{
    public static function encode(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_HASHTABLE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__compiler_json_encode_hashtable'),
                $context->helper->loadValue($arg)
            );
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            $boxed = $context->helper->loadValue($arg);
            $ht = $context->builder->call(
                $context->lookupFunction('__value__readHashtable'),
                $boxed
            );

            return $context->builder->call(
                $context->lookupFunction('__compiler_json_encode_hashtable'),
                $ht
            );
        }

        throw new \LogicException('json_encode() only supports arrays in this compiler build');
    }
}
