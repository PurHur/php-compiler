<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Shared VM helpers for array_diff_assoc / array_intersect_assoc (php-src ext/standard/array.c).
 */
trait VmArrayAssocSetOps
{
    /**
     * @param list<HashTable> $others
     */
    private static function pairInAnyOther(Variable $key, Variable $value, array $others): bool
    {
        foreach ($others as $haystack) {
            if (self::pairInHashTable($key, $value, $haystack)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<HashTable> $others
     */
    private static function pairInAllOthers(Variable $key, Variable $value, array $others): bool
    {
        foreach ($others as $haystack) {
            if (!self::pairInHashTable($key, $value, $haystack)) {
                return false;
            }
        }

        return true;
    }

    private static function pairInHashTable(Variable $key, Variable $value, HashTable $haystack): bool
    {
        $stored = self::valueAtKey($haystack, $key);
        if (null === $stored) {
            return false;
        }

        return $value->resolveIndirect()->identicalTo($stored->resolveIndirect());
    }

    /**
     * @param list<HashTable> $others
     */
    private static function keyInAnyOther(Variable $key, array $others): bool
    {
        foreach ($others as $haystack) {
            if (null !== self::valueAtKey($haystack, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<HashTable> $others
     */
    private static function keyInAllOthers(Variable $key, array $others): bool
    {
        foreach ($others as $haystack) {
            if (null === self::valueAtKey($haystack, $key)) {
                return false;
            }
        }

        return true;
    }

    private static function valueAtKey(HashTable $table, Variable $key): ?Variable
    {
        $key = $key->resolveIndirect();
        if (Variable::TYPE_INTEGER === $key->type) {
            return $table->findIndex($key->toInt());
        }
        if (Variable::TYPE_FLOAT === $key->type) {
            return $table->findIndex($key->toInt());
        }
        if (Variable::TYPE_STRING === $key->type) {
            return $table->find($key->toString());
        }

        return null;
    }

    /**
     * @param list<\PHPCompiler\VM\Variable> $calledArgs
     */
    private static function guardSetOpOperands(Frame $frame, array $calledArgs, string $fn): void
    {
        $first = $calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $first->type) {
            throw new \LogicException($fn.'() first argument must be an array in this compiler build');
        }
        $tables = [$first->toArray()];
        if (\count($calledArgs) > 1) {
            $tables = array_merge($tables, self::collectOtherHashTables($calledArgs, $fn));
        }
        VmArray::rejectEnumCaseSetOpOperands($frame, ...$tables);
    }

    /**
     * @param list<\PHPCompiler\VM\Variable> $calledArgs
     *
     * @return list<HashTable>
     */
    private static function collectOtherHashTables(array $calledArgs, string $fn): array
    {
        $others = [];
        for ($i = 1, $n = \count($calledArgs); $i < $n; ++$i) {
            $arg = $calledArgs[$i]->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $arg->type) {
                throw new \LogicException($fn.'() arguments must be arrays in this compiler build');
            }
            $others[] = $arg->toArray();
        }

        return $others;
    }
}
