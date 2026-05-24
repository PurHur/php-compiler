<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * VM helpers for array_multisort() (issue #1212).
 */
final class VmArrayMultisort
{
    /**
     * Sort packed list arrays together by the first array's values.
     *
     * @param list<HashTable> $tables
     */
    public static function apply(array $tables, bool $descending = false): void
    {
        if ([] === $tables) {
            throw new \LogicException('array_multisort() requires at least one array');
        }
        $primary = $tables[0];
        $count = $primary->getNumElements();
        if ($count < 2) {
            return;
        }
        foreach ($tables as $i => $ht) {
            if ($ht->getNumElements() !== $count) {
                throw new \LogicException(
                    'array_multisort() array #'.((int) $i + 1).' length must match the first array'
                );
            }
        }
        $primaryValues = [];
        foreach ($primary->iterate(true) as $value) {
            $primaryValues[] = $value;
        }
        $indices = range(0, $count - 1);
        \usort($indices, static function (int $a, int $b) use ($primaryValues, $descending): int {
            $cmp = self::compareValues($primaryValues[$a], $primaryValues[$b]);
            if ($descending) {
                $cmp = -$cmp;
            }

            return $cmp;
        });
        foreach ($tables as $ht) {
            $values = [];
            foreach ($ht->iterate(true) as $value) {
                $copy = new Variable();
                $copy->copyFrom($value);
                $values[] = $copy;
            }
            $reordered = [];
            foreach ($indices as $index) {
                $reordered[] = $values[$index];
            }
            $ht->replacePackedValues($reordered);
        }
    }

    private static function compareValues(Variable $a, Variable $b): int
    {
        $a = $a->resolveIndirect();
        $b = $b->resolveIndirect();
        if (Variable::TYPE_STRING === $a->type && Variable::TYPE_STRING === $b->type) {
            return VmString::strcmp($a->toString(), $b->toString());
        }
        if (Variable::TYPE_INTEGER === $a->type && Variable::TYPE_INTEGER === $b->type) {
            return $a->toInt() <=> $b->toInt();
        }

        throw new \LogicException(
            'array_multisort() only supports homogeneous string or integer arrays in this compiler build'
        );
    }
}
