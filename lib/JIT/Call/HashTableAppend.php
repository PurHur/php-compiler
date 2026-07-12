<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** HashTable::append() for nested php-in-PHP JIT helpers (#16075 / VmPregMatches). */
final class HashTableAppend implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (count($args) < 2) {
            throw new \LogicException('append() requires HashTable receiver and Variable value');
        }
        $ht = HashTableNestedReceiver::hashtableFromReceiver($context, $args[0]);
        self::appendValue($context, $ht, $args[1]);

        return HashTableNestedReceiver::nullVariableResult($context);
    }

    private static function appendValue(Context $context, Value $ht, Variable $element): void
    {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $index = $context->builder->load($context->builder->structGep($ht, $map['nextFreeElement']));
        $one = $sizeT->constInt(1, false);
        $need = $context->builder->addNoSignedWrap($index, $one);
        $context->builder->call($context->lookupFunction('__hashtable__grow'), $ht, $need);
        HashTableHelper::setAtIndex($context, $ht, $index, $element);

        $nextFree = $context->builder->load($context->builder->structGep($ht, $map['nextFreeElement']));
        $numElements = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $updateNext = $context->builder->icmp(Builder::INT_UGE, $index, $nextFree);
        $newNext = $context->builder->select($updateNext, $need, $nextFree);
        $context->builder->store(
            $newNext,
            $context->builder->structGep($ht, $map['nextFreeElement'])
        );
        $updateNum = $context->builder->icmp(Builder::INT_UGE, $index, $numElements);
        $newNum = $context->builder->select($updateNum, $need, $numElements);
        $context->builder->store(
            $newNum,
            $context->builder->structGep($ht, $map['numElements'])
        );
    }
}
