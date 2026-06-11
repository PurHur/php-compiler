<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
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
        if (false === $result) {
            return [
                'hosts' => HashTableHelper::alloc($context),
                'weights' => HashTableHelper::alloc($context),
                'ok' => false,
            ];
        }

        return [
            'hosts' => self::materializeStringList($context, $result['hosts']),
            'weights' => self::materializeIntList($context, $result['weights']),
            'ok' => true,
        ];
    }

    /**
     * @param list<string> $hosts
     */
    private static function materializeStringList(Context $context, array $hosts): Value
    {
        $ht = HashTableHelper::alloc($context);
        $sizeT = $context->getTypeFromString('size_t');
        foreach ($hosts as $index => $host) {
            $str = $context->builder->load(
                $context->constantStringFromString($host)
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

    /**
     * @param list<int> $weights
     */
    private static function materializeIntList(Context $context, array $weights): Value
    {
        $ht = HashTableHelper::alloc($context);
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        foreach ($weights as $index => $weight) {
            $context->builder->call(
                $context->lookupFunction('__hashtable__setLongAt'),
                $ht,
                $sizeT->constInt($index, false),
                $i64->constInt($weight, false)
            );
        }

        return $ht;
    }
}
