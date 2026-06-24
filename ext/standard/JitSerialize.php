<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringSerialize;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

final class JitSerialize
{
    public static function encode(Context $context, JITVariable $arg): Value
    {
        StringSerialize::ensureLinked($context);

        if (JITVariable::TYPE_HASHTABLE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__compiler_serialize_hashtable'),
                $context->helper->loadValue($arg)
            );
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            $boxed = $context->helper->loadValue($arg);
            $ht = $context->builder->call(
                $context->lookupFunction('__value__readHashtable'),
                $boxed
            );
            $htType = $context->getTypeFromString('__hashtable__*');
            $isArray = $context->builder->icmp(
                \PHPLLVM\Builder::INT_NE,
                $ht,
                $htType->constNull()
            );
            $arrayResult = $context->builder->call(
                $context->lookupFunction('__compiler_serialize_hashtable'),
                $ht
            );

            return $context->builder->select(
                $isArray,
                $arrayResult,
                $context->builder->call(
                    $context->lookupFunction('__compiler_serialize_value'),
                    $boxed
                )
            );
        }

        throw new \LogicException('serialize() value type not supported in this compiler build');
    }
}
