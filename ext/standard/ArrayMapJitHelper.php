<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

/**
 * array_map() paths for compiled JIT/AOT modules (#10183, #14977, php-in-PHP).
 *
 * SSOT: {@see array_map} VM execute path
 * php-src: ext/standard/array.c — php_array_map()
 *
 * NestedJIT: use exportKeyValuePairs, not iterateKeyed (#12908 / #23974) — iterateKeyed
 * returns Traversable while NestedJIT materializes a pair-list hashtable; foreach on the
 * Traversable shape segfaults under thin standalone AOT.
 */
final class ArrayMapJitHelper
{
    public static function mapNullIdentity(HashTable $src): HashTable
    {
        $out = new HashTable();
        foreach ($src->exportKeyValuePairs(true) as [$key, $value]) {
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
        foreach ($src->exportKeyValuePairs(true) as [$key, $value]) {
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

    public static function mapWithClosure(HashTable $src, Variable $closure): HashTable
    {
        $out = new HashTable();
        foreach ($src->exportKeyValuePairs(true) as [$key, $value]) {
            $mapped = VmClosureCall::invokeVariable($closure, $value);
            self::appendKeyed($out, $key, $mapped);
        }

        return $out;
    }

    /**
     * @param HashTable $sources packed list of source arrays (JIT bridge)
     */
    public static function mapWithClosureMultiple(HashTable $sources, Variable $closure): HashTable
    {
        $tables = [];
        foreach ($sources->iterate(true) as $value) {
            $tables[] = $value->resolveIndirect()->toArray();
        }

        return self::mapWithClosureMultipleTablesViaTarget($tables, $closure);
    }

    /**
     * @param list<HashTable> $sources
     */
    private static function mapWithClosureMultipleTables(
        \PHPCompiler\VM\Context $ctx,
        array $sources,
        \PHPCompiler\VM\ClosureState $closure
    ): HashTable {
        $out = new HashTable();
        $first = $sources[0];
        $destIdx = 0;
        foreach ($first->exportKeyValuePairs(true) as [$key, $_value]) {
            $rowArgs = [];
            foreach ($sources as $ht) {
                $rowArgs[] = self::valueAtKey($ht, $key);
            }
            $mapped = VmClosureCall::invoke($ctx, $closure, ...$rowArgs);
            $out->addIndex($destIdx++, $mapped);
        }

        return $out;
    }

    /**
     * Thin-AOT NestedJIT multi-array map (#24156).
     *
     * @param list<HashTable> $sources
     */
    private static function mapWithClosureMultipleTablesViaTarget(
        array $sources,
        Variable $closure
    ): HashTable {
        $out = new HashTable();
        $first = $sources[0];
        $destIdx = 0;
        foreach ($first->exportKeyValuePairs(true) as [$key, $_value]) {
            $rowArgs = [];
            foreach ($sources as $ht) {
                $rowArgs[] = self::valueAtKey($ht, $key);
            }
            $mapped = VmClosureCall::invokeVariable($closure, ...$rowArgs);
            $out->addIndex($destIdx++, $mapped);
        }

        return $out;
    }

    /**
     * Null-callback zip across multiple source arrays (php-src php_array_map).
     *
     * @param HashTable $sources packed list of source arrays (JIT bridge)
     */
    public static function mapNullZipMultiple(HashTable $sources): HashTable
    {
        $tables = [];
        foreach ($sources->iterate(true) as $value) {
            $tables[] = $value->resolveIndirect()->toArray();
        }

        return self::mapNullZipMultipleTables($tables);
    }

    /**
     * @param list<HashTable> $sources
     */
    private static function mapNullZipMultipleTables(array $sources): HashTable
    {
        $out = new HashTable();
        $first = $sources[0];
        $destIdx = 0;
        foreach ($first->exportKeyValuePairs(true) as [$key, $_value]) {
            $rowArgs = [];
            foreach ($sources as $ht) {
                $rowArgs[] = self::valueAtKey($ht, $key);
            }
            $mapped = new Variable();
            $mapped->array(self::buildZipRow($rowArgs));
            $out->addIndex($destIdx++, $mapped);
        }

        return $out;
    }

    /**
     * @param list<Variable> $values
     */
    private static function buildZipRow(array $values): HashTable
    {
        $row = new HashTable();
        $idx = 0;
        foreach ($values as $value) {
            $copy = new Variable();
            $copy->copyFrom($value);
            $row->addIndex($idx++, $copy);
        }

        return $row;
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
        foreach ($first->exportKeyValuePairs(true) as [$key, $_value]) {
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
        if (Variable::TYPE_STRING === $key->type) {
            $found = $ht->find($key->toString());
        } elseif (Variable::TYPE_INTEGER === $key->type) {
            $found = $ht->findIndex($key->toInt());
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
