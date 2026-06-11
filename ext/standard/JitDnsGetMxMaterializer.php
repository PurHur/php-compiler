<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\VM\HashTable;
use PHPLLVM\Value;

/** Materialize {@see VmDns::dnsGetMx()} at JIT compile time (#4125). */
final class JitDnsGetMxMaterializer
{
    /**
     * @return array{hosts: Value, weights: Value, ok: bool}
     */
    public static function materialize(Context $context, string $hostname): array
    {
        $result = VmDns::dnsGetMx($hostname);

        return [
            'hosts' => self::materializeList($context, $result['hosts']),
            'weights' => self::materializeWeights($context, $result['weights']),
            'ok' => $result['ok'],
        ];
    }

    private static function materializeList(Context $context, HashTable $hosts): Value
    {
        $ht = HashTableHelper::alloc($context);
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        foreach ($hosts->iterateKeyed(true) as [$key, $value]) {
            $index = $key->resolveIndirect()->toInt();
            $str = $context->builder->load(
                $context->constantStringFromString($value->toString())
            );
            $context->builder->call(
                $context->lookupFunction('__hashtable__setStringAt'),
                $ht,
                $sizeT->constInt($index, false),
                $str
            );
        }

        return $ht;
    }

    private static function materializeWeights(Context $context, HashTable $weights): Value
    {
        $ht = HashTableHelper::alloc($context);
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        foreach ($weights->iterateKeyed(true) as [$key, $value]) {
            $index = $key->resolveIndirect()->toInt();
            $long = $i64->constInt($value->toInt(), false);
            $context->builder->call(
                $context->lookupFunction('__hashtable__setLongAt'),
                $ht,
                $sizeT->constInt($index, false),
                $long
            );
        }

        return $ht;
    }
}
