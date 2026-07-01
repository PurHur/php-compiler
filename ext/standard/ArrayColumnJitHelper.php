<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * array_column() compile-time key paths for compiled JIT/AOT modules (#14256, php-in-PHP).
 *
 * SSOT shared with {@see array_column} VM execute()
 * php-src: ext/standard/array.c — php_array_column()
 */
final class ArrayColumnJitHelper
{
    public static function columnWithKey(HashTable $ht, string|int $columnKey): HashTable
    {
        $out = new HashTable();
        foreach ($ht->iterate(true) as $rowVar) {
            $row = $rowVar->resolveIndirect();
            $columnVal = self::readColumnFromRow($row, $columnKey);
            if (null === $columnVal) {
                continue;
            }
            $stored = new Variable();
            $stored->copyFrom($columnVal);
            $out->append($stored);
        }

        return $out;
    }

    public static function columnWithKeyAndIndex(
        HashTable $ht,
        string|int $columnKey,
        string|int $indexKey
    ): HashTable {
        $out = new HashTable();
        foreach ($ht->iterate(true) as $rowVar) {
            $row = $rowVar->resolveIndirect();
            $indexVal = self::readColumnFromRow($row, $indexKey);
            $columnVal = self::readColumnFromRow($row, $columnKey);
            if (null === $indexVal || null === $columnVal) {
                continue;
            }
            $stored = new Variable();
            $stored->copyFrom($columnVal);
            self::storeAtKey($out, $indexVal, $stored);
        }

        return $out;
    }

    public static function columnNull(HashTable $ht): HashTable
    {
        $out = new HashTable();
        foreach ($ht->iterate(true) as $rowVar) {
            $row = $rowVar->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $row->type && Variable::TYPE_OBJECT !== $row->type) {
                $stored = new Variable();
                $stored->copyFrom($row);
                $out->append($stored);
                continue;
            }
            $stored = new Variable();
            $stored->copyFrom($row);
            $out->append($stored);
        }

        return $out;
    }

    public static function columnNullWithIndex(HashTable $ht, string|int $indexKey): HashTable
    {
        $out = new HashTable();
        foreach ($ht->iterate(true) as $rowVar) {
            $row = $rowVar->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $row->type && Variable::TYPE_OBJECT !== $row->type) {
                $stored = new Variable();
                $stored->copyFrom($row);
                $indexVal = self::readColumnFromRow($row, $indexKey);
                if (null === $indexVal) {
                    $out->append($stored);
                    continue;
                }
                self::storeAtKey($out, $indexVal, $stored);
                continue;
            }
            $stored = new Variable();
            $stored->copyFrom($row);
            $indexVal = self::readColumnFromRow($row, $indexKey);
            if (null === $indexVal) {
                continue;
            }
            self::storeAtKey($out, $indexVal, $stored);
        }

        return $out;
    }

    public static function columnWithRuntimeKey(HashTable $ht, Variable $columnKey): HashTable
    {
        $field = VmArrayColumnArg::requireStrIntArg($columnKey, 'array_column', 1, 'column_key');

        return self::columnWithKey($ht, $field);
    }

    public static function columnWithRuntimeKeyAndIndex(
        HashTable $ht,
        Variable $columnKey,
        string|int $indexKey
    ): HashTable {
        $field = VmArrayColumnArg::requireStrIntArg($columnKey, 'array_column', 1, 'column_key');

        return self::columnWithKeyAndIndex($ht, $field, $indexKey);
    }

    public static function columnWithKeyAndRuntimeIndex(
        HashTable $ht,
        string|int $columnKey,
        Variable $indexKey
    ): HashTable {
        $indexField = VmArrayColumnArg::requireStrIntArg($indexKey, 'array_column', 2, 'index_key');

        return self::columnWithKeyAndIndex($ht, $columnKey, $indexField);
    }

    public static function columnWithRuntimeKeyAndRuntimeIndex(
        HashTable $ht,
        Variable $columnKey,
        Variable $indexKey
    ): HashTable {
        $columnField = VmArrayColumnArg::requireStrIntArg($columnKey, 'array_column', 1, 'column_key');
        $indexField = VmArrayColumnArg::requireStrIntArg($indexKey, 'array_column', 2, 'index_key');

        return self::columnWithKeyAndIndex($ht, $columnField, $indexField);
    }

    public static function columnNullWithRuntimeIndex(HashTable $ht, Variable $indexKey): HashTable
    {
        $indexField = VmArrayColumnArg::requireStrIntArg($indexKey, 'array_column', 2, 'index_key');

        return self::columnNullWithIndex($ht, $indexField);
    }

    private static function readColumnFromRow(Variable $row, string|int $field): ?Variable
    {
        if (Variable::TYPE_ARRAY === $row->type) {
            $rowHt = $row->toArray();
            $cell = \is_int($field) ? $rowHt->findIndex($field) : $rowHt->find($field);
            if (null === $cell || $cell->isUndefined()) {
                return null;
            }

            return $cell->resolveIndirect();
        }
        if (Variable::TYPE_OBJECT === $row->type) {
            $propName = \is_string($field) ? $field : (string) $field;
            $object = $row->toObject();
            if (!$object->hasProperty($propName)) {
                return null;
            }

            return $object->getProperty($propName)->resolveIndirect();
        }

        return null;
    }

    private static function storeAtKey(HashTable $out, Variable $key, Variable $value): void
    {
        $stored = new Variable();
        $stored->copyFrom($value);
        $resolved = $key->resolveIndirect();
        if (Variable::TYPE_INTEGER === $resolved->type) {
            $out->updateIndex($resolved->toInt(), $stored);

            return;
        }
        if (Variable::TYPE_STRING === $resolved->type) {
            $out->update($resolved->toString(), $stored);

            return;
        }
        if (Variable::TYPE_NULL === $resolved->type) {
            $out->update('', $stored);

            return;
        }
        throw new \LogicException(
            'array_column() index_key value must be int, string, or null in this compiler build'
        );
    }
}
