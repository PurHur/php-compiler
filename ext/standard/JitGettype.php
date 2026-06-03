<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for boxed gettype() from ext/standard (#3618, #5235). */
final class JitGettype
{
    public static function boxed(Context $context, JITVariable $arg): Value
    {
        $valuePtr = JitValueBox::normalizeValuePtr(
            $context,
            JitValueBox::valuePtrFromVariable($context, $arg)
        );
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $result = $context->builder->load($context->constantStringFromString('unknown'));
        foreach ([
            JITVariable::TYPE_NULL => 'NULL',
            JITVariable::TYPE_NATIVE_BOOL => 'boolean',
            JITVariable::TYPE_NATIVE_DOUBLE => 'double',
            JITVariable::TYPE_STRING => 'string',
            JITVariable::TYPE_OBJECT => 'object',
            JITVariable::TYPE_HASHTABLE => 'array',
        ] as $jitType => $name) {
            $expected = $i8->constInt($jitType, false);
            $isType = $context->builder->icmp(Builder::INT_EQ, $typeByte, $expected);
            $candidate = $context->builder->load($context->constantStringFromString($name));
            $result = $context->builder->select($isType, $candidate, $result);
        }
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_NATIVE_LONG, false)
        );
        $handle = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $valuePtr
        );
        $isResource = JitIsResource::invoke($context, $handle);
        $longLabel = $context->builder->select(
            $isResource,
            $context->builder->load($context->constantStringFromString('resource')),
            $context->builder->load($context->constantStringFromString('integer'))
        );

        return $context->builder->select($isLong, $longLabel, $result);
    }
}
