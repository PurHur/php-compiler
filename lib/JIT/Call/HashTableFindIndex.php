<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableReadLlvm;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * HashTable::findIndex() for nested php-in-PHP JIT helpers (ArrayPushJitHelper #12719).
 *
 * PHI predecessors must be the block that actually branches to merge — HashTableReadLlvm
 * advances the insert block (#23974 ArrayMap NestedJIT verify).
 */
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
        $nullEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($readBb);
        $valueVar = HashTableReadLlvm::readIndexedToValueBox($context, $ht, $index);
        $readSlot = JitValueBox::valuePtrFromVariable($context, $valueVar);
        $readEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($mergeBb);
        $phi = $context->builder->phi($context->getTypeFromString('__value__*'));
        $phi->addIncoming($nullSlot, $nullEnd);
        $phi->addIncoming($readSlot, $readEnd);

        return $phi;
    }

    private static function indexAsSizeT(Context $context, Variable $index): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        if (Variable::TYPE_NATIVE_LONG === $index->type && null !== $index->compileTimeLong) {
            return $sizeT->constInt($index->compileTimeLong, false);
        }
        if (Variable::TYPE_NATIVE_LONG === $index->type && Variable::KIND_LITERAL === $index->kind) {
            return $sizeT->constInt((int) $index->literal, false);
        }
        if (Variable::TYPE_VALUE === $index->type) {
            $ptr = JitValueBox::valuePtrFromVariable($context, $index);
            $long = $context->builder->call($context->lookupFunction('__value__readLong'), $ptr);

            return JitNestedHelperCoerce::i64ToScalar(
                $context,
                JitNestedHelperCoerce::scalarToI64($context, $long, $context->getTypeFromString('int64')),
                $sizeT
            );
        }

        return JitNestedHelperCoerce::i64ToScalar(
            $context,
            JitNestedHelperCoerce::scalarToI64(
                $context,
                $context->helper->loadValue($index),
                $context->getTypeFromString('int32')
            ),
            $sizeT
        );
    }
}
