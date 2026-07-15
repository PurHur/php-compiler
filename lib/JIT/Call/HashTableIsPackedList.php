<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * HashTable::isPackedList() heuristic for nested php-in-PHP JIT helpers (#19048).
 *
 * Matches dense numeric lists (no string keys, numElements == nextFreeElement).
 * php-src: ext/standard/array.c packed-list checks; SSOT {@see \PHPCompiler\VM\HashTable::isPackedList()}.
 */
final class HashTableIsPackedList implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('isPackedList() requires a HashTable receiver');
        }
        $ht = HashTableNestedReceiver::hashtableFromReceiver($context, $args[0]);
        $map = $context->structFieldMap['__hashtable__'];
        $i1 = $context->getTypeFromString('int1');
        $nodePtrTy = $context->getTypeFromString('__strkey_node__*');

        $numElements = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $nextFree = $context->builder->load($context->builder->structGep($ht, $map['nextFreeElement']));
        $strKeys = $context->builder->load($context->builder->structGep($ht, $map['strKeys']));

        $zero = $context->getTypeFromString('size_t')->constInt(0, false);
        $empty = $context->builder->icmp(Builder::INT_EQ, $numElements, $zero);
        $noStrKeys = $context->builder->icmp(Builder::INT_EQ, $strKeys, $nodePtrTy->constNull());
        $dense = $context->builder->icmp(Builder::INT_EQ, $numElements, $nextFree);

        return $context->builder->or(
            $empty,
            $context->builder->and($noStrKeys, $dense)
        );
    }
}
