<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM for array_replace_recursive / HashTable::replaceRecursiveCopy (#26977).
 *
 * Ported from ArrayBuiltinHelper (#3166/#3997) before NestedJIT-only routing (#18409).
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_replace_recursive)
 */
final class HashTableReplaceRecursiveLlvm
{
    private static int $copyListEntrySeq = 0;

    /**
     * Mask IS_REFCOUNTED — do not treat VM TYPE_ARRAY (6) as HT (collides with TYPE_VALUE&0x7f) (#26977).
     */
    private static function isHashtableTypeByte(Context $context, \PHPLLVM\Value $typeByte): \PHPLLVM\Value
    {
        $i8 = $context->getTypeFromString('int8');
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));

        return $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_HASHTABLE & 0x7f, false)
        );
    }



    /** NestedJIT / bridge: copy with zero overlays. */
    public static function replaceSingle(Context $context, Value $ht): Value
    {
        $result = HashTableHelper::alloc($context);
        self::overlayHashTable($context, $result, $ht);

        return $result;
    }

    /** NestedJIT / bridge: base then one overlay. */
    public static function replaceTwo(Context $context, Value $left, Value $right): Value
    {
        $result = HashTableHelper::alloc($context);
        self::overlayHashTable($context, $result, $left);
        self::replaceRecursiveOverlayPackedIndices($context, $result, $right);
        self::replaceRecursiveMergeStringKeys($context, $result, $right);
        self::replaceRecursiveAddMissingStringKeys($context, $result, $right);

        return $result;
    }


    private static function copyPackedListEntry(
        Context $context,
        Value $src,
        Value $srcIndex,
        Value $dest,
        Value $destIndex
    ): void {
        // Typed copy — raw entry KIND_VARIABLE misreads nested HT as int(0) (#26977 / #24232).
        $elem = HashTableReadLlvm::readIndexedToValueBox($context, $src, $srcIndex);
        HashTableWriteLlvm::setAtIndex($context, $dest, $destIndex, $elem);
    }

    private static function listEntryAt(Context $context, Value $ht, Value $index): Value
    {
        $map = $context->structFieldMap['__hashtable__'];
        $values = $context->builder->load(
            $context->builder->structGep($ht, $map['values'])
        );

        return $context->builder->inBoundsGep($values, $index);
    }

    private static function storeValueEntryAtStringKey(
        Context $context,
        Value $dest,
        Value $keyStr,
        Value $valEntry
    ): void {
        $slot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer($context, $slot, $valEntry);
        $elem = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
        HashTableWriteLlvm::setAtStringKey($context, $dest, $keyStr, $elem);
    }

    private static function overlayHashTable(Context $context, Value $dest, Value $src): void
    {
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');

        $nextFree = $context->builder->load($context->builder->structGep($src, $map['nextFreeElement']));
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);

        $packedHead = BasicBlockHelper::append($context, 'array_replace_overlay_packed_head');
        $packedBody = BasicBlockHelper::append($context, 'array_replace_overlay_packed_body');
        $packedSet = BasicBlockHelper::append($context, 'array_replace_overlay_packed_set');
        $packedNext = BasicBlockHelper::append($context, 'array_replace_overlay_packed_next');
        $packedDone = BasicBlockHelper::append($context, 'array_replace_overlay_packed_done');
        $context->builder->branch($packedHead);

        $context->builder->positionAtEnd($packedHead);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $packedDone, $packedBody);

        $context->builder->positionAtEnd($packedBody);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $idx
        );
        $context->builder->branchIf($isSet, $packedSet, $packedNext);

        $context->builder->positionAtEnd($packedSet);
        self::copyPackedListEntry($context, $src, $idx, $dest, $idx);
        $context->builder->branch($packedNext);

        $context->builder->positionAtEnd($packedNext);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($packedHead);

        $strInit = BasicBlockHelper::append($context, 'array_replace_overlay_str_init');
        $strHead = BasicBlockHelper::append($context, 'array_replace_overlay_str_head');
        $context->builder->positionAtEnd($packedDone);
        $context->builder->branch($strInit);

        $context->builder->positionAtEnd($strInit);
        $walkSlot = BasicBlockHelper::entryAlloca($context, $nodePtrType);
        $head = $context->builder->load($context->builder->structGep($src, $map['strKeys']));
        $context->builder->store($head, $walkSlot);
        $strBody = BasicBlockHelper::append($context, 'array_replace_overlay_str_body');
        $strSet = BasicBlockHelper::append($context, 'array_replace_overlay_str_set');
        $strNext = BasicBlockHelper::append($context, 'array_replace_overlay_str_next');
        $strDone = BasicBlockHelper::append($context, 'array_replace_overlay_str_done');
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $node = $context->builder->load($walkSlot);
        $nodeNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($nodeNull, $strDone, $strBody);

        $context->builder->positionAtEnd($strBody);
        $valEntry = $context->builder->structGep($node, $nodeMap['value']);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $context->builder->branch($strSet);

        $context->builder->positionAtEnd($strSet);
        self::storeValueEntryAtStringKey($context, $dest, $keyStr, $valEntry);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strNext);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strDone);
    }
    /**
     * array_replace_recursive() — nested key merge (ext/standard/array.c parity; #3166).
     */
    public static function arrayReplaceRecursive(Context $context, Variable $first, Variable ...$others): Value
    {
        $result = HashTableHelper::alloc($context);
        self::overlayHashTable($context, $result, ArrayBuiltinHelper::loadHashTable($context, $first));
        foreach ($others as $other) {
            $otherHt = ArrayBuiltinHelper::loadHashTable($context, $other);
            self::replaceRecursiveOverlayPackedIndices($context, $result, $otherHt);
            self::replaceRecursiveMergeStringKeys($context, $result, $otherHt);
            self::replaceRecursiveAddMissingStringKeys($context, $result, $otherHt);
        }

        return $result;
    }
    /**
     * Packed-index overlay for array_replace_recursive() (#3166).
     */
    private static function replaceRecursiveOverlayPackedIndices(
        Context $context,
        Value $dest,
        Value $src
    ): void {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $valueMap = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $htType = Variable::TYPE_HASHTABLE;

        $nextFree = $context->builder->load($context->builder->structGep($src, $map['nextFreeElement']));
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'array_replace_rec_packed_head');
        $body = BasicBlockHelper::append($context, 'array_replace_rec_packed_body');
        $set = BasicBlockHelper::append($context, 'array_replace_rec_packed_set');
        $next = BasicBlockHelper::append($context, 'array_replace_rec_packed_next');
        $done = BasicBlockHelper::append($context, 'array_replace_rec_packed_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $idx
        );
        $context->builder->branchIf($isSet, $set, $next);

        $context->builder->positionAtEnd($set);
        $destHas = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $dest,
            $idx
        );
        $srcVal = self::listEntryAt($context, $src, $idx);
        $__ty_srcIsHt = $context->builder->load($context->builder->structGep($srcVal, $valueMap['type']));
        $srcIsHt = self::isHashtableTypeByte($context, $__ty_srcIsHt);
        $destVal = self::listEntryAt($context, $dest, $idx);
        $__ty_destIsHt = $context->builder->load($context->builder->structGep($destVal, $valueMap['type']));
        $destIsHt = self::isHashtableTypeByte($context, $__ty_destIsHt);
        $bothHt = $context->builder->and(
            $destHas,
            $context->builder->and($srcIsHt, $destIsHt)
        );
        $copy = BasicBlockHelper::append($context, 'array_replace_rec_packed_copy');
        $merge = BasicBlockHelper::append($context, 'array_replace_rec_packed_merge');
        $context->builder->branchIf($bothHt, $merge, $copy);

        $context->builder->positionAtEnd($copy);
        self::copyPackedListEntry($context, $src, $idx, $dest, $idx);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($merge);
        $existingHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $destVal
        );
        $overlayHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $srcVal
        );
        $merged = HashTableHelper::alloc($context);
        self::overlayHashTable($context, $merged, $existingHt);
        self::replaceRecursiveAddMissingStringKeys($context, $merged, $overlayHt);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setHashtableAt'),
            $dest,
            $idx,
            $merged
        );
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }
    /**
     * Merge existing string keys when both values are hashtables (VM in-place parity; #3166).
     */
    private static function replaceRecursiveMergeStringKeys(
        Context $context,
        Value $dest,
        Value $src
    ): void {
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $valueMap = $context->structFieldMap['__value__'];
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $valuePtrType = $context->getTypeFromString('__value__*');
        $i8 = $context->getTypeFromString('int8');
        $htType = Variable::TYPE_HASHTABLE;

        $strInit = BasicBlockHelper::append($context, 'array_replace_rec_merge_str_init');
        $strHead = BasicBlockHelper::append($context, 'array_replace_rec_merge_str_head');
        $context->builder->branch($strInit);

        $context->builder->positionAtEnd($strInit);
        $walkSlot = BasicBlockHelper::entryAlloca($context, $nodePtrType);
        $head = $context->builder->load($context->builder->structGep($src, $map['strKeys']));
        $context->builder->store($head, $walkSlot);
        $strBody = BasicBlockHelper::append($context, 'array_replace_rec_merge_str_body');
        $strSet = BasicBlockHelper::append($context, 'array_replace_rec_merge_str_set');
        $strNext = BasicBlockHelper::append($context, 'array_replace_rec_merge_str_next');
        $strDone = BasicBlockHelper::append($context, 'array_replace_rec_merge_str_done');
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $node = $context->builder->load($walkSlot);
        $nodeNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($nodeNull, $strDone, $strBody);

        $context->builder->positionAtEnd($strBody);
        $valEntry = $context->builder->structGep($node, $nodeMap['value']);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $context->builder->branch($strSet);

        $context->builder->positionAtEnd($strSet);
        $existingPtr = $context->builder->call(
            $context->lookupFunction('__hashtable__peekStringKeyValue'),
            $dest,
            $keyStr
        );
        $existingNull = $context->builder->icmp(Builder::INT_EQ, $existingPtr, $valuePtrType->constNull());
        $skip = BasicBlockHelper::append($context, 'array_replace_rec_merge_str_skip');
        $replace = BasicBlockHelper::append($context, 'array_replace_rec_merge_str_replace');
        $context->builder->branchIf($existingNull, $skip, $replace);

        $context->builder->positionAtEnd($replace);
        $__ty_srcIsHt = $context->builder->load($context->builder->structGep($valEntry, $valueMap['type']));
        $srcIsHt = self::isHashtableTypeByte($context, $__ty_srcIsHt);
        $__ty_existingIsHt = $context->builder->load($context->builder->structGep($existingPtr, $valueMap['type']));
        $existingIsHt = self::isHashtableTypeByte($context, $__ty_existingIsHt);
        $bothHt = $context->builder->and($srcIsHt, $existingIsHt);
        $scalarReplace = BasicBlockHelper::append($context, 'array_replace_rec_merge_str_scalar');
        $deepMerge = BasicBlockHelper::append($context, 'array_replace_rec_merge_str_deep');
        $context->builder->branchIf($bothHt, $deepMerge, $scalarReplace);

        $context->builder->positionAtEnd($scalarReplace);
        self::storeValueEntryAtStringKey($context, $dest, $keyStr, $valEntry);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($deepMerge);
        $existingHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $existingPtr
        );
        $overlayHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $valEntry
        );
        self::replaceRecursiveAddMissingStringKeys($context, $existingHt, $overlayHt);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($skip);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strNext);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strDone);
    }
    /**
     * Add string keys from {@param $src} missing in {@param $dest} (#3166).
     */
    private static function replaceRecursiveAddMissingStringKeys(
        Context $context,
        Value $dest,
        Value $src
    ): void {
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $valuePtrType = $context->getTypeFromString('__value__*');

        $strInit = BasicBlockHelper::append($context, 'array_replace_rec_add_str_init');
        $strHead = BasicBlockHelper::append($context, 'array_replace_rec_add_str_head');
        $context->builder->branch($strInit);

        $context->builder->positionAtEnd($strInit);
        $walkSlot = BasicBlockHelper::entryAlloca($context, $nodePtrType);
        $head = $context->builder->load($context->builder->structGep($src, $map['strKeys']));
        $context->builder->store($head, $walkSlot);
        $strBody = BasicBlockHelper::append($context, 'array_replace_rec_add_str_body');
        $strSet = BasicBlockHelper::append($context, 'array_replace_rec_add_str_set');
        $strNext = BasicBlockHelper::append($context, 'array_replace_rec_add_str_next');
        $strDone = BasicBlockHelper::append($context, 'array_replace_rec_add_str_done');
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $node = $context->builder->load($walkSlot);
        $nodeNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($nodeNull, $strDone, $strBody);

        $context->builder->positionAtEnd($strBody);
        $valEntry = $context->builder->structGep($node, $nodeMap['value']);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $context->builder->branch($strSet);

        $context->builder->positionAtEnd($strSet);
        $existingPtr = $context->builder->call(
            $context->lookupFunction('__hashtable__peekStringKeyValue'),
            $dest,
            $keyStr
        );
        $existingNull = $context->builder->icmp(Builder::INT_EQ, $existingPtr, $valuePtrType->constNull());
        $doSet = BasicBlockHelper::append($context, 'array_replace_rec_add_str_do_set');
        $context->builder->branchIf($existingNull, $doSet, $strNext);

        $context->builder->positionAtEnd($doSet);
        self::storeValueEntryAtStringKey($context, $dest, $keyStr, $valEntry);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strNext);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strDone);
    }
}
