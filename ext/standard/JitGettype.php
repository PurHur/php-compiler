<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StreamGlobalsJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for boxed gettype() from ext/standard (#3618, #5235, #30792). */
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
            VmVariable::TYPE_ENUM_CASE => 'object',
        ] as $jitType => $name) {
            $expected = $i8->constInt($jitType, false);
            $isType = $context->builder->icmp(Builder::INT_EQ, $typeByte, $expected);
            $candidate = $context->builder->load($context->constantStringFromString($name));
            $result = $context->builder->select($isType, $candidate, $result);
        }
        $isHt = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_HASHTABLE, false)
        );
        $htLabel = $context->builder->select(
            JitStreamContextRepresentation::isRepresentation(
                $context,
                $context->builder->call(
                    $context->lookupFunction('__value__readHashtable'),
                    $valuePtr
                )
            ),
            $context->builder->load($context->constantStringFromString('resource')),
            $context->builder->load($context->constantStringFromString('array'))
        );
        $result = $context->builder->select($isHt, $htLabel, $result);
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_NATIVE_LONG, false)
        );
        $handle = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $valuePtr
        );

        return $context->builder->select($isLong, self::labelForHandle($context, $handle), $result);
    }

    /**
     * gettype() for stream handles — open / closed / plain int (#30792).
     *
     * php-src: closed resources keep the zval but is_resource is false; gettype says
     * "resource (closed)" when {@see StreamGlobalsJit::GLOBAL_WAS_USED} is set.
     */
    public static function labelForHandle(Context $context, Value $handle): Value
    {
        $isResource = JitIsResource::invoke($context, $handle);
        $closed = self::isClosedStreamHandle($context, $handle);

        return $context->builder->select(
            $isResource,
            $context->builder->load($context->constantStringFromString('resource')),
            $context->builder->select(
                $closed,
                $context->builder->load($context->constantStringFromString('resource (closed)')),
                $context->builder->load($context->constantStringFromString('integer'))
            )
        );
    }

    /** True when handle was opened (was_used) but {@see __compiler_is_resource} is now false. */
    private static function isClosedStreamHandle(Context $context, Value $handle): Value
    {
        StreamGlobalsJit::ensureGlobals($context);
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);
        $three = $i64->constInt(3, false);
        $max = $i64->constInt(StreamGlobalsJit::MAX_HANDLES, false);
        $false = $context->constantFromBool(false);

        $inRange = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $handle, $three),
            $context->builder->icmp(Builder::INT_SLT, $handle, $max)
        );
        $global = $context->module->getNamedGlobal(StreamGlobalsJit::GLOBAL_WAS_USED);
        if (null === $global) {
            return $false;
        }
        $slot = $context->builder->gep($global, $zero, $handle);
        $wasUsed = $context->builder->load($slot);
        $used = $context->builder->icmp(Builder::INT_NE, $wasUsed, $i8->constInt(0, false));

        return $context->builder->and($inRange, $used);
    }
}
