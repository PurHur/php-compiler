<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

final class JitJsonDecode
{
    public static function materializeNull(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return $ptr;
    }

    /**
     * @param int|float|bool|string $scalar
     */
    public static function materializeScalar(Context $context, $scalar): Value
    {
        $slot = JitValueBox::alloc($context);
        if (\is_bool($scalar)) {
            JitValueBox::writeLong(
                $context,
                $slot,
                $context->getTypeFromString('int64')->constInt($scalar ? 1 : 0, false)
            );
        } elseif (\is_int($scalar)) {
            JitValueBox::writeLong(
                $context,
                $slot,
                $context->getTypeFromString('int64')->constInt($scalar, false)
            );
        } elseif (\is_float($scalar)) {
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                JitValueBox::pointer($context, $slot),
                $context->getTypeFromString('double')->constReal($scalar, false)
            );
        } else {
            $str = $context->builder->load($context->constantStringFromString((string) $scalar));
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                JitValueBox::pointer($context, $slot),
                $str
            );
        }

        return JitValueBox::pointer($context, $slot);
    }

    public static function materializeArray(Context $context, array $data): Value
    {
        $ht = self::buildHashtableFromPhp($context, $data);
        $context->refcount->addref($ht);

        return $ht;
    }

    public static function decodeRuntime(Context $context, JITVariable $json): Value
    {
        return self::decodeRuntimeString(
            $context,
            JitStringArg::lower($context, $json, 'json_decode() json')
        );
    }

    /** @return Value __value__* */
    public static function decodeRuntimeString(Context $context, Value $jsonString): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__compiler_json_decode'),
            $jsonString,
            $ptr
        );

        return $ptr;
    }

    private static function buildHashtableFromPhp(Context $context, array $data): Value
    {
        $ht = HashTableHelper::alloc($context);
        foreach ($data as $key => $value) {
            $keyStr = $context->builder->load($context->constantStringFromString((string) $key));
            if (\is_array($value)) {
                $child = self::buildHashtableFromPhp($context, $value);
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringKeyHashtable'),
                    $ht,
                    $keyStr,
                    $child
                );
                continue;
            }
            $context->builder->call(
                $context->lookupFunction('__hashtable__setStringKeyString'),
                $ht,
                $keyStr,
                self::scalarToJitString($context, $value)
            );
        }

        return $ht;
    }

    private static function scalarToJitString(Context $context, mixed $value): Value
    {
        if (\is_bool($value)) {
            $literal = $value ? '1' : '';
        } elseif (null === $value) {
            $literal = '';
        } elseif (\is_int($value) || \is_float($value)) {
            $literal = (string) $value;
        } elseif (\is_string($value)) {
            $literal = $value;
        } else {
            throw new \LogicException('json_decode() scalar type not supported for JIT materialization');
        }

        return $context->builder->load($context->constantStringFromString($literal));
    }
}
