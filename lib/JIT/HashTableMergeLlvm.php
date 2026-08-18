<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Value;

/**
 * Call-site LLVM for {@see \PHPCompiler\ext\standard\VmArray::merge()} (#27546).
 *
 * Thin AOT NestedJIT of {@see \PHPCompiler\ext\standard\ArrayMergeJitHelper} returned a
 * PHP HashTable that is not a native `__hashtable__` — implode/json_encode segfault after
 * `c:main_before_php` (peer {@see HashTableCombineLlvm} / #27132,
 * {@see ArrayFlipLlvm} / #26970 — direct packed/string walks, not exportPairs).
 *
 * Integer packed slots append (reindex); string keys overwrite via
 * {@see HashTableHelper::setAtStringKey} (json_encode-safe under thin AOT). Canonical
 * non-negative int string keys append like {@see \PHPCompiler\ext\standard\VmArray}.
 *
 * VM SSOT remains {@see \PHPCompiler\ext\standard\VmArray::merge()} /
 * {@see \PHPCompiler\ext\standard\ArrayMergeJitHelper}.
 * php-src: ext/standard/array.c — php_array_merge()
 */
final class HashTableMergeLlvm
{
    private static int $seq = 0;

    private static function nextSeq(): int
    {
        return ++self::$seq;
    }

    /** array_merge() single-arg copy — reindex int keys, preserve string keys. */
    public static function mergeSingle(Context $context, Value $srcHt): Value
    {
        $dest = HashTableHelper::alloc($context);
        self::mergeArrayInto($context, $dest, $srcHt);

        return $dest;
    }

    /** array_merge($left, $right) — copy $left then merge $right into the result. */
    public static function mergeTwo(Context $context, Value $leftHt, Value $rightHt): Value
    {
        $dest = self::mergeSingle($context, $leftHt);
        self::mergeArrayInto($context, $dest, $rightHt);

        return $dest;
    }

    /**
     * Merge $srcHt into $dest (mutates $dest).
     *
     * php-src / VmArray::mergeArrayInto — int keys append; string keys overwrite.
     */
    public static function mergeArrayInto(Context $context, Value $dest, Value $srcHt): void
    {
        self::appendPackedEntries($context, $dest, $srcHt);
        self::mergeStringEntries($context, $dest, $srcHt);
    }

    private static function appendPackedEntries(Context $context, Value $dest, Value $srcHt): void
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

        $head = BasicBlockHelper::append($context, 'ht_merge_pk_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_merge_pk_body_'.$tag);
        $take = BasicBlockHelper::append($context, 'ht_merge_pk_take_'.$tag);
        $next = BasicBlockHelper::append($context, 'ht_merge_pk_next_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_merge_pk_done_'.$tag);
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
        $destNext = $context->builder->load(
            $context->builder->structGep($dest, $htMap['nextFreeElement'])
        );
        HashTableHelper::setAtIndex($context, $dest, $destNext, $valVar);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function mergeStringEntries(Context $context, Value $dest, Value $srcHt): void
    {
        $tag = (string) self::nextSeq();
        $htMap = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrTy = $context->getTypeFromString('__strkey_node__*');

        $nodeSlot = BasicBlockHelper::entryAlloca($context, $nodePtrTy);
        $headNode = $context->builder->load($context->builder->structGep($srcHt, $htMap['strKeys']));
        $context->builder->store($headNode, $nodeSlot);

        $head = BasicBlockHelper::append($context, 'ht_merge_sk_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_merge_sk_body_'.$tag);
        $next = BasicBlockHelper::append($context, 'ht_merge_sk_next_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_merge_sk_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $node = $context->builder->load($nodeSlot);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrTy->constNull());
        $context->builder->branchIf($isNull, $done, $body);

        $context->builder->positionAtEnd($body);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $valField = $context->builder->structGep($node, $nodeMap['value']);
        $valSlot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer($context, $valSlot, $valField);
        $valVar = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $valSlot);
        self::mergeStringKey($context, $dest, $keyStr, $valVar);

        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $nodeSlot);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    /**
     * Canonical non-negative int string keys append (VmArray::isCanonicalNonNegativeIntStringKey);
     * otherwise overwrite/add at the string key (json_encode-safe HashTableHelper path).
     */
    private static function mergeStringKey(
        Context $context,
        Value $dest,
        Value $keyStr,
        Variable $valVar
    ): void {
        $tag = (string) self::nextSeq();
        $map = $context->structFieldMap['__string__'];
        $htMap = $context->structFieldMap['__hashtable__'];
        $len = $context->builder->load($context->builder->structGep($keyStr, $map['length']));
        $charPtr = $context->builder->structGep($keyStr, $map['value']);
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zeroLen = $len->typeOf()->constInt(0, false);

        $useStr = BasicBlockHelper::append($context, 'ht_merge_sk_str_'.$tag);
        $tryInt = BasicBlockHelper::append($context, 'ht_merge_sk_try_'.$tag);
        $append = BasicBlockHelper::append($context, 'ht_merge_sk_append_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_merge_sk_done_'.$tag);

        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $zeroLen);
        $context->builder->branchIf($isEmpty, $useStr, $tryInt);

        $context->builder->positionAtEnd($tryInt);
        // Match VmArray::isCanonicalNonNegativeIntStringKey: (string)(int)$s === $s && >= 0.
        $firstChar = $context->builder->load($charPtr);
        $isDigit = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $firstChar, $i8->constInt(ord('0'), false)),
            $context->builder->icmp(Builder::INT_SLE, $firstChar, $i8->constInt(ord('9'), false))
        );
        $lenGtOne = $context->builder->icmp(
            Builder::INT_UGT,
            $len,
            $len->typeOf()->constInt(1, false)
        );
        $leadingZero = $context->builder->and(
            $lenGtOne,
            $context->builder->icmp(Builder::INT_EQ, $firstChar, $i8->constInt(ord('0'), false))
        );
        $endPtrSlot = $context->builder->alloca($i8p, 1, 'ht_merge_sk_end_'.$tag);
        $context->builder->store($i8p->constNull(), $endPtrSlot);
        // strtol(3) via LibcExtern::ensureStrtolDecl after always-on drop (#31988).
        LibcExtern::ensureStrtolDecl($context);
        $parsed = $context->builder->call(
            $context->lookupFunction('strtol'),
            $charPtr,
            $endPtrSlot,
            $context->getTypeFromString('int32')->constInt(10, false)
        );
        $endPtr = $context->builder->load($endPtrSlot);
        $endOffset = $context->builder->sub(
            $context->builder->ptrToInt($endPtr, $i64),
            $context->builder->ptrToInt($charPtr, $i64)
        );
        $consumedAll = $context->builder->icmp(Builder::INT_EQ, $endOffset, $len);
        $nonNeg = $context->builder->icmp(Builder::INT_SGE, $parsed, $i64->constInt(0, false));
        $canonical = $context->builder->and(
            $context->builder->and($consumedAll, $nonNeg),
            $context->builder->and($isDigit, $context->builder->not($leadingZero))
        );
        $context->builder->branchIf($canonical, $append, $useStr);

        $context->builder->positionAtEnd($append);
        $destNext = $context->builder->load(
            $context->builder->structGep($dest, $htMap['nextFreeElement'])
        );
        HashTableHelper::setAtIndex($context, $dest, $destNext, $valVar);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($useStr);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $keyStr
        );
        // Same write path as ArrayFlipLlvm / HashTableCombineLlvm (json_encode-safe).
        HashTableHelper::setAtStringKey($context, $dest, $owned, $valVar);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }
}
