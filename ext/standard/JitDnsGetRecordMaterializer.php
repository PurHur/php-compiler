<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPLLVM\Value;

/** Materialize {@see VmDns::dnsGetRecord()} at JIT compile time (#6392). */
final class JitDnsGetRecordMaterializer
{
    /**
     * @return array{records: Value, authns: Value, addtl: Value, ok: bool}
     */
    public static function materialize(Context $context, string $hostname, int $type, bool $raw = false): array
    {
        $result = VmDns::dnsGetRecord($hostname, $type, $raw);
        if (false === $result) {
            return [
                'records' => HashTableHelper::alloc($context),
                'authns' => HashTableHelper::alloc($context),
                'addtl' => HashTableHelper::alloc($context),
                'ok' => false,
            ];
        }

        return [
            'records' => self::materializeRecordList($context, $result),
            'authns' => HashTableHelper::alloc($context),
            'addtl' => HashTableHelper::alloc($context),
            'ok' => true,
        ];
    }

    private static function materializeRecordList(Context $context, \PHPCompiler\VM\HashTable $records): Value
    {
        $ht = HashTableHelper::alloc($context);
        $sizeT = $context->getTypeFromString('size_t');
        $setHt = $context->lookupFunction('__hashtable__setHashtableAt');
        $index = 0;
        foreach ($records->iterateKeyed(true) as $pair) {
            [, $recordVar] = $pair;
            $recordVar = $recordVar->resolveIndirect();
            if (\PHPCompiler\VM\Variable::TYPE_ARRAY !== $recordVar->type) {
                continue;
            }
            $recordHt = self::materializeAssocRecord($context, $recordVar->toArray());
            $context->builder->call(
                $setHt,
                $ht,
                $sizeT->constInt($index, false),
                $recordHt
            );
            ++$index;
        }

        return $ht;
    }

    private static function materializeAssocRecord(Context $context, \PHPCompiler\VM\HashTable $record): Value
    {
        $ht = HashTableHelper::alloc($context);
        $setString = $context->lookupFunction('__hashtable__setStringKeyString');
        $setLong = $context->lookupFunction('__hashtable__setStringKeyLong');
        foreach ($record->iterateKeyed(true) as $pair) {
            [$keyVar, $valVar] = $pair;
            $key = $keyVar->resolveIndirect()->toString();
            $valVar = $valVar->resolveIndirect();
            $keyStr = $context->builder->load($context->constantStringFromString($key));
            if (\PHPCompiler\VM\Variable::TYPE_STRING === $valVar->type) {
                $valStr = $context->builder->load($context->constantStringFromString($valVar->toString()));
                $context->builder->call($setString, $ht, $keyStr, $valStr);
            } elseif (\PHPCompiler\VM\Variable::TYPE_LONG === $valVar->type) {
                $i64 = $context->getTypeFromString('int64');
                $context->builder->call(
                    $setLong,
                    $ht,
                    $keyStr,
                    $i64->constInt($valVar->toInt(), false)
                );
            }
        }

        return $ht;
    }
}
