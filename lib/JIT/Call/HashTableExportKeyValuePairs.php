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

/** HashTable::exportKeyValuePairs for nested php-in-PHP JIT helpers (#12910). */
final class HashTableExportKeyValuePairs implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('exportKeyValuePairs() requires a HashTable receiver');
        }
        $ht = self::hashtableFromReceiver($context, $args[0]);

        return self::exportPairs($context, $ht);
    }

    private static function hashtableFromReceiver(Context $context, Variable $receiver): Value
    {
        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        if (Variable::TYPE_HASHTABLE === $receiver->type) {
            return HashTableHelper::loadHashtablePointer($context, $receiver);
        }
        $objPtr = $context->helper->loadValue($receiver);

        return $context->builder->bitcast($objPtr, $htPtrTy);
    }

    private static function exportPairs(Context $context, Value $ht): Value
    {
        $result = HashTableHelper::alloc($context);
        $outIdxSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('size_t'));
        $context->builder->store($context->getTypeFromString('size_t')->constInt(0, false), $outIdxSlot);

        self::exportNumericKeys($context, $ht, $result, $outIdxSlot);
        self::exportStringKeys($context, $ht, $result, $outIdxSlot);

        return $result;
    }

    private static function exportNumericKeys(
        Context $context,
        Value $ht,
        Value $result,
        Value $outIdxSlot
    ): void {
        $htMap = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $indexSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $limit = $context->builder->load($context->builder->structGep($ht, $htMap['nextFreeElement']));
        $context->builder->store($zero, $indexSlot);
        $valuesBase = $context->builder->load($context->builder->structGep($ht, $htMap['values']));

        $head = BasicBlockHelper::append($context, 'ht_export_num_head');
        $body = BasicBlockHelper::append($context, 'ht_export_num_body');
        $done = BasicBlockHelper::append($context, 'ht_export_num_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($indexSlot);
        $past = $context->builder->icmp(Builder::INT_SGE, $idx, $limit);
        $context->builder->branchIf($past, $done, $body);

        $context->builder->positionAtEnd($body);
        $valueMap = $context->structFieldMap['__value__'];
        $entry = $context->builder->gep($valuesBase, $idx);
        $typeByte = $context->builder->load($context->builder->structGep($entry, $valueMap['type']));
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isNull = $context->builder->icmp(Builder::INT_EQ, $kind, $i8->constInt(0, false));
        $skipBb = BasicBlockHelper::append($context, 'ht_export_num_skip');
        $addBb = BasicBlockHelper::append($context, 'ht_export_num_add');
        $context->builder->branchIf($isNull, $skipBb, $addBb);

        $context->builder->positionAtEnd($addBb);
        $keyVar = self::longValueBox($context, $context->builder->zExt($idx, $i64));
        $valVar = HashTableReadLlvm::readIndexedToValueBox($context, $ht, $idx);
        self::appendPair($context, $result, $outIdxSlot, $keyVar, $valVar);
        $context->builder->branch($skipBb);

        $context->builder->positionAtEnd($skipBb);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $indexSlot);
        $context->builder->branch($head);
        $context->builder->positionAtEnd($done);
    }

    private static function exportStringKeys(
        Context $context,
        Value $ht,
        Value $result,
        Value $outIdxSlot
    ): void {
        $htMap = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrTy = $context->getTypeFromString('__strkey_node__*');
        $map = $context->structFieldMap['__string__'];
        $sizeT = $context->getTypeFromString('size_t');
        $one = $sizeT->constInt(1, false);

        $nodeSlot = BasicBlockHelper::entryAlloca($context, $nodePtrTy);
        $headNode = $context->builder->load($context->builder->structGep($ht, $htMap['strKeys']));
        $context->builder->store($headNode, $nodeSlot);

        $head = BasicBlockHelper::append($context, 'ht_export_str_head');
        $body = BasicBlockHelper::append($context, 'ht_export_str_body');
        $done = BasicBlockHelper::append($context, 'ht_export_str_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $node = $context->builder->load($nodeSlot);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrTy->constNull());
        $context->builder->branchIf($isNull, $done, $body);

        $context->builder->positionAtEnd($body);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $keyLen = $context->builder->load($context->builder->structGep($keyStr, $map['length']));
        $keyPtr = $context->builder->structGep($keyStr, $map['value']);
        $keyVar = self::stringValueBox($context, $keyPtr, $keyLen);
        $valField = $context->builder->structGep($node, $nodeMap['value']);
        $valVar = self::valueBoxFromEntry($context, $valField);
        self::appendPair($context, $result, $outIdxSlot, $keyVar, $valVar);

        $next = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($next, $nodeSlot);
        $context->builder->branch($head);
        $context->builder->positionAtEnd($done);
    }

    private static function appendPair(
        Context $context,
        Value $result,
        Value $outIdxSlot,
        Variable $keyVar,
        Variable $valVar
    ): void {
        $resolvedVal = self::copyValueBox($context, $valVar);
        $pairHt = HashTableHelper::alloc($context);
        $zero = $context->getTypeFromString('size_t')->constInt(0, false);
        $one = $context->getTypeFromString('size_t')->constInt(1, false);
        HashTableHelper::setAtIndex($context, $pairHt, $zero, $keyVar);
        HashTableHelper::setAtIndex($context, $pairHt, $one, $resolvedVal);
        $outIdx = $context->builder->load($outIdxSlot);
        HashTableHelper::setAtIndex(
            $context,
            $result,
            $outIdx,
            new Variable($context, Variable::TYPE_HASHTABLE, Variable::KIND_VALUE, $pairHt)
        );
        $context->builder->store($context->builder->addNoSignedWrap($outIdx, $one), $outIdxSlot);
    }

    private static function copyValueBox(Context $context, Variable $valVar): Variable
    {
        if (Variable::TYPE_VALUE === $valVar->type && Variable::KIND_VARIABLE === $valVar->kind) {
            return $valVar;
        }
        $srcPtr = JitValueBox::valuePtrFromVariable($context, $valVar);
        $slot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer($context, $slot, $srcPtr);

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
    }

    private static function valueBoxFromEntry(Context $context, Value $entryPtr): Variable
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer($context, $slot, $entryPtr);

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
    }

    private static function longValueBox(Context $context, Value $long): Variable
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            JitValueBox::pointer($context, $slot),
            $long
        );

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
    }

    private static function stringValueBox(Context $context, Value $ptr, Value $len): Variable
    {
        $slot = JitValueBox::alloc($context);
        $str = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $ptr
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $str
        );

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
    }
}
