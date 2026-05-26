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
}
