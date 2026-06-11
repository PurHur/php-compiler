<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPLLVM\Value;

/**
 * Materialize {@see VmDate::dateSunInfoNative()} output into LLVM hashtables at JIT compile time (#6831).
 */
final class JitDateSunInfoMaterializer
{
    /**
     * @param array<string, int|bool> $parsed
     */
    public static function materializeParsed(Context $context, array $parsed): Value
    {
        $ht = HashTableHelper::alloc($context);
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        foreach ($parsed as $key => $value) {
            $keyStr = $context->builder->load($context->constantStringFromString((string) $key));
            if (\is_int($value)) {
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringKeyLong'),
                    $ht,
                    $keyStr,
                    $i64->constInt($value, false)
                );
                continue;
            }
            $context->builder->call(
                $context->lookupFunction('__hashtable__setStringKeyBool'),
                $ht,
                $keyStr,
                $i1->constInt($value ? 1 : 0, false)
            );
        }

        return $ht;
    }
}
