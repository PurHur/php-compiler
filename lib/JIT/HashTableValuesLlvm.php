<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * NestedJIT / call-site LLVM for {@see \PHPCompiler\VM\HashTable::valuesCopy()} (#27212, #27545).
 *
 * Must not call {@see Builtin\ArrayValuesRuntime} — NestedJIT of
 * {@see \PHPCompiler\ext\standard\ArrayValuesJitHelper} would recurse
 * (peer {@see HashTableKeysLlvm} / {@see HashTableReverseLlvm}).
 *
 * Thin AOT: walk packed slots + {@see strKeys} directly (peer {@see HashTableMergeLlvm} /
 * {@see ArrayFlipLlvm}). Avoid {@see Call\HashTableExportKeyValuePairs} pair-list materialization.
 *
 * php-src: ext/standard/array.c — php_array_values()
 */
final class HashTableValuesLlvm
{
    private static int $seq = 0;

    private static function nextSeq(): int
    {
        return ++self::$seq;
    }

    public static function values(Context $context, Value $srcHt): Value
    {
        $dest = HashTableHelper::alloc($context);
        self::appendPackedValues($context, $dest, $srcHt);
        self::appendStringKeyValues($context, $dest, $srcHt);

        return $dest;
    }

    private static function appendPackedValues(Context $context, Value $dest, Value $srcHt): void
    {
        $tag = (string) self::nextSeq();
        $htMap = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $nextFree = $context->builder->load(
            $context->builder->structGep($srcHt, $htMap['nextFreeElement'])
        );
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'ht_values_pk_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_values_pk_body_'.$tag);
        $take = BasicBlockHelper::append($context, 'ht_values_pk_take_'.$tag);
        $next = BasicBlockHelper::append($context, 'ht_values_pk_next_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_values_pk_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $srcHt,
            $idx
        );
        $context->builder->branchIf($isSet, $take, $next);

        $context->builder->positionAtEnd($take);
        $valVar = HashTableReadLlvm::readIndexedToValueBox($context, $srcHt, $idx);
        self::appendValue($context, $dest, $valVar);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function appendStringKeyValues(Context $context, Value $dest, Value $srcHt): void
    {
        $tag = (string) self::nextSeq();
        $htMap = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrTy = $context->getTypeFromString('__strkey_node__*');

        $nodeSlot = BasicBlockHelper::entryAlloca($context, $nodePtrTy);
        $headNode = $context->builder->load($context->builder->structGep($srcHt, $htMap['strKeys']));
        $context->builder->store($headNode, $nodeSlot);

        $head = BasicBlockHelper::append($context, 'ht_values_sk_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_values_sk_body_'.$tag);
        $next = BasicBlockHelper::append($context, 'ht_values_sk_next_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_values_sk_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $node = $context->builder->load($nodeSlot);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrTy->constNull());
        $context->builder->branchIf($isNull, $done, $body);

        $context->builder->positionAtEnd($body);
        $valField = $context->builder->structGep($node, $nodeMap['value']);
        $valSlot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer($context, $valSlot, $valField);
        $valVar = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $valSlot);
        self::appendValue($context, $dest, $valVar);

        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $nodeSlot);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function appendValue(Context $context, Value $dest, Variable $valVar): void
    {
        $htMap = $context->structFieldMap['__hashtable__'];
        $destNext = $context->builder->load(
            $context->builder->structGep($dest, $htMap['nextFreeElement'])
        );
        HashTableHelper::setAtIndex($context, $dest, $destNext, $valVar);
    }
}
