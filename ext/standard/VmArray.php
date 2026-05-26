<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/** VM array helpers (no PHP internal wrappers in compiled paths). */
final class VmArray
{
    public static function isList(HashTable $ht): bool
    {
        $n = $ht->getNumElements();
        if (0 === $n) {
            return true;
        }
        $expected = 0;
        foreach ($ht->iterateKeyed() as $pair) {
            $keyVar = $pair[0];
            if (Variable::TYPE_INTEGER !== $keyVar->type) {
                return false;
            }
            if ($keyVar->toInt() !== $expected) {
                return false;
            }
            ++$expected;
        }

        return $expected === $n;
    }

    /**
     * array_merge() subset: packed 0..n-1 lists append; string-key maps overwrite later keys (#2287).
     *
     * @param HashTable ...$others
     */
    public static function merge(HashTable $first, HashTable ...$others): HashTable
    {
        foreach ([$first, ...$others] as $ht) {
            if (!self::isList($ht)) {
                $out = $first->replaceCopy();
                foreach ($others as $other) {
                    $out->mergeStringKeysFrom($other, true);
                }

                return $out;
            }
        }

        return $first->mergeCopy(...$others);
    }

    public static function keyFirst(HashTable $ht): ?Variable
    {
        $ht->iterReset();
        if (!$ht->iterValid()) {
            return null;
        }

        return $ht->iterCurrentKey();
    }

    public static function keyLast(HashTable $ht): ?Variable
    {
        $ht->iterReset();
        $last = null;
        while ($ht->iterValid()) {
            $last = $ht->iterCurrentKey();
        }

        return $last;
    }

    /**
     * array_pad() — pad packed list {@param $array} to abs({@param $length}) with {@param $value}.
     */
    public static function pad(HashTable $array, int $length, Variable $value): HashTable
    {
        return $array->padCopy($length, $value);
    }

    /**
     * array_fill_keys() — keys from values of {@param $keys}, uniform {@param $value}.
     */
    public static function fillKeys(HashTable $keys, Variable $value): HashTable
    {
        $dest = new HashTable();
        foreach ($keys->iterateKeyed(true) as [, $keyValue]) {
            $stored = new Variable();
            $stored->copyFrom($value);
            if (Variable::TYPE_INTEGER === $keyValue->type) {
                $dest->addIndex($keyValue->toInt(), $stored);
            } elseif (Variable::TYPE_STRING === $keyValue->type) {
                $dest->add($keyValue->toString(), $stored);
            } else {
                throw new \ValueError(
                    'array_fill_keys(): Argument #1 ($keys) must contain only integer and string keys'
                );
            }
        }

        return $dest;
    }

    /** ksort() — return array sorted by key; packed lists are unchanged. */
    public static function ksortCopy(HashTable $ht): HashTable
    {
        if ($ht->getNumElements() < 2 || self::isList($ht)) {
            return $ht;
        }
        $pairs = [];
        foreach ($ht->iterateKeyed(true) as [$key, $value]) {
            $keyCopy = new Variable();
            $keyCopy->copyFrom($key);
            $valCopy = new Variable();
            $valCopy->copyFrom($value);
            $pairs[] = [$keyCopy, $valCopy];
        }
        VmInternalCompare::sortKeyedPairsByKey($pairs);
        $sorted = new HashTable();
        foreach ($pairs as [$key, $value]) {
            $resolvedKey = $key->resolveIndirect();
            $copy = new Variable();
            $copy->copyFrom($value);
            if (Variable::TYPE_INTEGER === $resolvedKey->type) {
                $sorted->addIndex($resolvedKey->toInt(), $copy);
            } elseif (Variable::TYPE_STRING === $resolvedKey->type) {
                $sorted->add($resolvedKey->toString(), $copy);
            } else {
                throw new \LogicException(
                    'ksort() only supports homogeneous string or integer keys in this compiler build'
                );
            }
        }

        return $sorted;
    }

    /** krsort() — return array sorted by key descending; packed lists are unchanged. */
    public static function krsortCopy(HashTable $ht): HashTable
    {
        if ($ht->getNumElements() < 2 || self::isList($ht)) {
            return $ht;
        }
        $pairs = [];
        foreach ($ht->iterateKeyed(true) as [$key, $value]) {
            $keyCopy = new Variable();
            $keyCopy->copyFrom($key);
            $valCopy = new Variable();
            $valCopy->copyFrom($value);
            $pairs[] = [$keyCopy, $valCopy];
        }
        VmInternalCompare::sortKeyedPairsByKeyDesc($pairs);
        $sorted = new HashTable();
        foreach ($pairs as [$key, $value]) {
            $resolvedKey = $key->resolveIndirect();
            $copy = new Variable();
            $copy->copyFrom($value);
            if (Variable::TYPE_INTEGER === $resolvedKey->type) {
                $sorted->addIndex($resolvedKey->toInt(), $copy);
            } elseif (Variable::TYPE_STRING === $resolvedKey->type) {
                $sorted->add($resolvedKey->toString(), $copy);
            } else {
                throw new \LogicException(
                    'krsort() only supports homogeneous string or integer keys in this compiler build'
                );
            }
        }

        return $sorted;
    }

    /** asort() — return array sorted by value ascending; packed lists are unchanged (handled in-place). */
    public static function asortCopy(HashTable $ht): HashTable
    {
        if ($ht->getNumElements() < 2 || self::isList($ht)) {
            return $ht;
        }
        $pairs = [];
        foreach ($ht->iterateKeyed(true) as [$key, $value]) {
            $keyCopy = new Variable();
            $keyCopy->copyFrom($key);
            $valCopy = new Variable();
            $valCopy->copyFrom($value);
            $pairs[] = [$keyCopy, $valCopy];
        }
        $first = $pairs[0][1]->resolveIndirect();
        if (Variable::TYPE_STRING === $first->type) {
            VmInternalCompare::sortKeyedPairsByValue(
                $pairs,
                VmInternalCompare::resolveStringCallback('strcmp')
            );
        } elseif (Variable::TYPE_INTEGER === $first->type) {
            VmInternalCompare::sortKeyedPairsByValueInt($pairs);
        } else {
            throw new \LogicException(
                'asort() only supports homogeneous string or integer values in this compiler build'
            );
        }
        $sorted = new HashTable();
        foreach ($pairs as [$key, $value]) {
            $resolvedKey = $key->resolveIndirect();
            $copy = new Variable();
            $copy->copyFrom($value);
            if (Variable::TYPE_INTEGER === $resolvedKey->type) {
                $sorted->addIndex($resolvedKey->toInt(), $copy);
            } elseif (Variable::TYPE_STRING === $resolvedKey->type) {
                $sorted->add($resolvedKey->toString(), $copy);
            } else {
                throw new \LogicException(
                    'asort() only supports homogeneous string or integer keys in this compiler build'
                );
            }
        }

        return $sorted;
    }

    /** natsort() — return array sorted by value using natural order; packed lists unchanged (in-place). */
    public static function natsortCopy(HashTable $ht): HashTable
    {
        if ($ht->getNumElements() < 2 || self::isList($ht)) {
            return $ht;
        }
        $pairs = [];
        foreach ($ht->iterateKeyed(true) as [$key, $value]) {
            $keyCopy = new Variable();
            $keyCopy->copyFrom($key);
            $valCopy = new Variable();
            $valCopy->copyFrom($value);
            $pairs[] = [$keyCopy, $valCopy];
        }
        $first = $pairs[0][1]->resolveIndirect();
        if (Variable::TYPE_STRING === $first->type) {
            VmInternalCompare::sortKeyedPairsByValue(
                $pairs,
                VmInternalCompare::resolveStringCallback('strnatcmp')
            );
        } elseif (Variable::TYPE_INTEGER === $first->type) {
            VmInternalCompare::sortKeyedPairsByValueInt($pairs);
        } else {
            throw new \LogicException(
                'natsort() only supports homogeneous string or integer values in this compiler build'
            );
        }
        $sorted = new HashTable();
        foreach ($pairs as [$key, $value]) {
            $resolvedKey = $key->resolveIndirect();
            $copy = new Variable();
            $copy->copyFrom($value);
            if (Variable::TYPE_INTEGER === $resolvedKey->type) {
                $sorted->addIndex($resolvedKey->toInt(), $copy);
            } elseif (Variable::TYPE_STRING === $resolvedKey->type) {
                $sorted->add($resolvedKey->toString(), $copy);
            } else {
                throw new \LogicException(
                    'natsort() only supports homogeneous string or integer keys in this compiler build'
                );
            }
        }

        return $sorted;
    }

    /** arsort() — return array sorted by value descending; packed lists are unchanged (handled in-place). */
    public static function arsortCopy(HashTable $ht): HashTable
    {
        if ($ht->getNumElements() < 2 || self::isList($ht)) {
            return $ht;
        }
        $pairs = [];
        foreach ($ht->iterateKeyed(true) as [$key, $value]) {
            $keyCopy = new Variable();
            $keyCopy->copyFrom($key);
            $valCopy = new Variable();
            $valCopy->copyFrom($value);
            $pairs[] = [$keyCopy, $valCopy];
        }
        $first = $pairs[0][1]->resolveIndirect();
        if (Variable::TYPE_STRING === $first->type) {
            VmInternalCompare::sortKeyedPairsByValueDesc(
                $pairs,
                VmInternalCompare::resolveStringCallback('strcmp')
            );
        } elseif (Variable::TYPE_INTEGER === $first->type) {
            VmInternalCompare::sortKeyedPairsByValueIntDesc($pairs);
        } else {
            throw new \LogicException(
                'arsort() only supports homogeneous string or integer values in this compiler build'
            );
        }
        $sorted = new HashTable();
        foreach ($pairs as [$key, $value]) {
            $resolvedKey = $key->resolveIndirect();
            $copy = new Variable();
            $copy->copyFrom($value);
            if (Variable::TYPE_INTEGER === $resolvedKey->type) {
                $sorted->addIndex($resolvedKey->toInt(), $copy);
            } elseif (Variable::TYPE_STRING === $resolvedKey->type) {
                $sorted->add($resolvedKey->toString(), $copy);
            } else {
                throw new \LogicException(
                    'arsort() only supports homogeneous string or integer keys in this compiler build'
                );
            }
        }

        return $sorted;
    }

    /**
     * shuffle() — Fisher–Yates on packed list values (CSPRNG via {@see VmString::randomBytes()}).
     */
    public static function shufflePacked(HashTable $ht): void
    {
        $n = $ht->getNumElements();
        if ($n < 2) {
            return;
        }
        if (!self::isList($ht)) {
            throw new \LogicException(
                'shuffle() only supports packed list arrays in this compiler build'
            );
        }
        $values = [];
        foreach ($ht->iterate(true) as $value) {
            $copy = new Variable();
            $copy->copyFrom($value);
            $values[] = $copy;
        }
        for ($i = $n - 1; $i > 0; --$i) {
            $rand = VmString::randomBytes(8);
            $pick = 0;
            for ($b = 0; $b < 8; ++$b) {
                $pick = ($pick << 8) | \ord($rand[$b]);
            }
            $j = $pick % ($i + 1);
            if ($j < 0) {
                $j += $i + 1;
            }
            $tmp = $values[$i];
            $values[$i] = $values[$j];
            $values[$j] = $tmp;
        }
        $ht->replacePackedValues($values);
    }

    /**
     * array_rand() — pick {@param $num} unique packed list keys (CSPRNG; issue #2321).
     */
    public static function arrayRandPacked(HashTable $ht, int $num): Variable
    {
        if (!self::isList($ht)) {
            throw new \LogicException(
                'array_rand() only supports packed list arrays in this compiler build'
            );
        }
        $n = $ht->getNumElements();
        if (0 === $n) {
            throw new \ValueError('array_rand(): Argument #1 ($array) cannot be empty');
        }
        if ($num < 1) {
            throw new \ValueError('array_rand(): Argument #2 ($num) must be greater than 0');
        }
        if ($num > $n) {
            throw new \ValueError(
                'array_rand(): Argument #2 ($num) must be between 1 and the number of elements in Argument #1 ($array)'
            );
        }
        $indices = [];
        for ($i = 0; $i < $n; ++$i) {
            $indices[] = $i;
        }
        for ($i = 0; $i < $num; ++$i) {
            $rand = VmString::randomBytes(8);
            $pick = 0;
            for ($b = 0; $b < 8; ++$b) {
                $pick = ($pick << 8) | \ord($rand[$b]);
            }
            $upper = $n - $i;
            $j = $i + ($pick % $upper);
            if ($j < 0) {
                $j = $i;
            }
            $tmp = $indices[$i];
            $indices[$i] = $indices[$j];
            $indices[$j] = $tmp;
        }
        $picked = \array_slice($indices, 0, $num);
        $result = new Variable();
        if (1 === $num) {
            $result->int($picked[0]);

            return $result;
        }
        $arr = new HashTable();
        foreach ($picked as $pos => $key) {
            $v = new Variable();
            $v->int($key);
            $arr->addIndex($pos, $v);
        }
        $result->array($arr);

        return $result;
    }

    /**
     * array_count_values() — count occurrences of string or integer values (#2356).
     */
    public static function countValues(HashTable $ht): HashTable
    {
        $out = new HashTable();
        foreach ($ht->iterateKeyed(true) as [, $value]) {
            $v = $value->resolveIndirect();
            if (Variable::TYPE_STRING === $v->type) {
                $key = $v->toString();
                $existing = $out->find($key);
                if (null === $existing) {
                    $count = new Variable();
                    $count->int(1);
                    $out->add($key, $count);
                } else {
                    $resolved = $existing->resolveIndirect();
                    $resolved->int($resolved->toInt() + 1);
                }
                continue;
            }
            if (Variable::TYPE_INTEGER === $v->type) {
                $idx = $v->toInt();
                $existing = $out->findIndex($idx);
                if (null === $existing) {
                    $count = new Variable();
                    $count->int(1);
                    $out->addIndex($idx, $count);
                } else {
                    $resolved = $existing->resolveIndirect();
                    $resolved->int($resolved->toInt() + 1);
                }
                continue;
            }
            throw new \LogicException(
                'array_count_values() only supports string and integer values in this compiler build'
            );
        }

        return $out;
    }
}
