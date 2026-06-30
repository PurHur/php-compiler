<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * array_map() single-array paths for compiled JIT/AOT modules (#10183, php-in-PHP).
 *
 * SSOT: {@see array_map} VM execute path
 * php-src: ext/standard/array.c — php_array_map()
 */
final class ArrayMapJitHelper
{
    public static function mapNullIdentity(HashTable $src): HashTable
    {
        $out = new HashTable();
        foreach ($src->iterateKeyed(true) as [$key, $value]) {
            $copy = new Variable();
            $copy->copyFrom($value);
            self::appendKeyed($out, $key, $copy);
        }

        return $out;
    }

    public static function mapWithBuiltin(HashTable $src, string $builtinName): HashTable
    {
        $fn = VmInternalCall::resolveStringCallback($builtinName);
        $out = new HashTable();
        foreach ($src->iterateKeyed(true) as [$key, $value]) {
            $mapped = VmInternalCall::invoke($fn, $value);
            self::appendKeyed($out, $key, $mapped);
        }

        return $out;
    }

    /**
     * @param list<HashTable> $sources
     */
    public static function mapWithBuiltinMultiple(HashTable $sources, string $builtinName): HashTable
    {
        $tables = [];
        foreach ($sources->iterate(true) as $value) {
            $tables[] = $value->resolveIndirect()->toArray();
        }

        return self::mapWithBuiltinMultipleTables($tables, $builtinName);
    }

    /**
     * @param list<HashTable> $sources
     */
    private static function mapWithBuiltinMultipleTables(array $sources, string $builtinName): HashTable
    {
        $fn = VmInternalCall::resolveStringCallback($builtinName);
        $out = new HashTable();
        $first = $sources[0];
        $destIdx = 0;
        foreach ($first->iterateKeyed(true) as [$key, $_value]) {
            $rowArgs = [];
            foreach ($sources as $ht) {
                $rowArgs[] = self::valueAtKey($ht, $key);
            }
            $mapped = VmInternalCall::invoke($fn, ...$rowArgs);
            $out->addIndex($destIdx++, $mapped);
        }

        return $out;
    }

    private static function valueAtKey(HashTable $ht, Variable $key): Variable
    {
        $found = null;
        if (Variable::TYPE_INTEGER === $key->type) {
            $found = $ht->findIndex($key->toInt());
        } elseif (Variable::TYPE_STRING === $key->type) {
            $found = $ht->find($key->toString());
        }
        $result = new Variable();
        if (null === $found) {
            $result->reset();
            $result->type = Variable::TYPE_NULL;

            return $result;
        }
        $resolved = $found->resolveIndirect();
        if ($resolved->isUndefined()) {
            $result->reset();
            $result->type = Variable::TYPE_NULL;

            return $result;
        }
        $result->copyFrom($found);

        return $result;
    }

    private static function appendKeyed(HashTable $out, Variable $key, Variable $value): void
    {
        if (Variable::TYPE_INTEGER === $key->type) {
            $out->addIndex($key->toInt(), $value);

            return;
        }
        $out->add($key->toString(), $value);
    }
}
