<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringSerialize;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
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
            // ABI wants __value__* (peer JitVarExport / #20773) — not a loaded __value__ struct.
            $boxed = JitValueBox::valuePtrFromVariable($context, $arg);
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

        // Object / string / native scalars — box then SerializeJitHelper::encodeValue (#23509 AOT).
        // Same bridge as var_export(); previously only TYPE_VALUE/HASHTABLE were accepted.
        return $context->builder->call(
            $context->lookupFunction('__compiler_serialize_value'),
            JitValueBox::valuePtrFromVariable($context, $arg)
        );
    }
}
