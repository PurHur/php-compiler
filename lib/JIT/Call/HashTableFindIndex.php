<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\HashTableReadLlvm;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** HashTable::findIndex() for nested php-in-PHP JIT helpers (ArrayPushJitHelper #12719). */
final class HashTableFindIndex implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('findIndex() requires HashTable receiver and int index');
        }

        $ht = HashTableNestedReceiver::hashtableFromReceiver($context, $args[0]);
        $index = self::indexAsSizeT($context, $args[1]);
        $limit = $context->builder->load(
            $context->builder->structGep($ht, $context->structFieldMap['__hashtable__']['nextFreeElement'])
        );
        $inRange = $context->builder->icmp(Builder::INT_SLT, $index, $limit);
        $nullBb = BasicBlockHelper::append($context, 'ht_find_index_null');
        $readBb = BasicBlockHelper::append($context, 'ht_find_index_read');
        $mergeBb = BasicBlockHelper::append($context, 'ht_find_index_merge');
        $context->builder->branchIf($inRange, $readBb, $nullBb);

        $context->builder->positionAtEnd($nullBb);
        $nullSlot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $nullSlot)
        );
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($readBb);
        $valueVar = HashTableReadLlvm::readIndexedToValueBox($context, $ht, $index);
        $readSlot = JitValueBox::valuePtrFromVariable($context, $valueVar);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($mergeBb);
        $phi = $context->builder->phi(
            $context->getTypeFromString('__value__*'),
            [
                [$nullSlot, $nullBb],
                [$readSlot, $readBb],
            ]
        );

        return $phi;
    }

    private static function indexAsSizeT(Context $context, Variable $index): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        if (Variable::TYPE_NATIVE_LONG === $index->type && Variable::KIND_LITERAL === $index->kind) {
            return $sizeT->constInt((int) $index->literal, false);
        }

        return $context->builder->trunc(
            $context->helper->loadValue($index),
            $sizeT
        );
    }
}
