<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * NestedJIT LLVM for HashTable COW duplicate / array union (#23548).
 *
 * {@see \PHPCompiler\VM\HashTableJitHelper} calls instance methods that must lower to LLVM
 * during NestedJIT — not via HashTableDuplicateRuntime / HashTableUnionRuntime bridges
 * (those NestedJIT-compile the same helper and would recurse).
 *
 * php-src: Zend/zend_hash.c — zend_array_dup; Zend/zend_operators.c — add_function union
 */
final class HashTableCowLlvm
{
    private static int $seq = 0;

    private static function nextSeq(): int
    {
        return ++self::$seq;
    }

    /** Deep-ish packed+string copy (zend_array_dup shape for NestedJIT helpers). */
    public static function duplicate(Context $context, Value $srcHt): Value
    {
        $dest = HashTableHelper::alloc($context);
        self::copyPackedPreservingIndex($context, $dest, $srcHt);
        self::copyStringKeys($context, $dest, $srcHt);

        return $dest;
    }

    /** Array union: left keys win (#3690 / #10533). */
    public static function union(Context $context, Value $leftHt, Value $rightHt): Value
    {
        $dest = self::duplicate($context, $leftHt);
        self::mergeMissingPacked($context, $dest, $rightHt);
        self::mergeMissingStringKeys($context, $dest, $rightHt);

        return $dest;
    }

    /**
     * array_replace(): copy left, then overwrite/add keys from right (#27519).
     *
     * php-src: ext/standard/array.c — PHP_FUNCTION(array_replace) / php_array_merge
     * (non-recursive overlay). VM SSOT: {@see \PHPCompiler\VM\HashTable::replaceCopy()}.
     */
    public static function replace(Context $context, Value $leftHt, Value $rightHt): Value
    {
        $dest = self::duplicate($context, $leftHt);
        self::overlayOnto($context, $dest, $rightHt);

        return $dest;
    }

    /** Overlay all keys from src onto dest (always overwrite when src set). */
    public static function overlayOnto(Context $context, Value $dest, Value $srcHt): void
    {
        self::overlayPacked($context, $dest, $srcHt);
        self::overlayStringKeys($context, $dest, $srcHt);
    }

    private static function copyPackedPreservingIndex(Context $context, Value $dest, Value $srcHt): void
    {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->builder->load($context->builder->structGep($srcHt, $map['nextFreeElement']));
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);
        $tag = (string) self::nextSeq();
        $head = BasicBlockHelper::append($context, 'ht_cow_dup_packed_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_cow_dup_packed_body_'.$tag);
        $advance = BasicBlockHelper::append($context, 'ht_cow_dup_packed_advance_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_cow_dup_packed_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $srcHt,
            $idx
        );
        $skip = BasicBlockHelper::append($context, 'ht_cow_dup_packed_skip_'.$tag);
        $copy = BasicBlockHelper::append($context, 'ht_cow_dup_packed_copy_'.$tag);
        $context->builder->branchIf($isSet, $copy, $skip);

        $context->builder->positionAtEnd($copy);
        $elem = HashTableReadLlvm::readIndexedToValueBox($context, $srcHt, $idx);
        HashTableWriteLlvm::setAtIndex($context, $dest, $idx, $elem);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($skip);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function copyStringKeys(Context $context, Value $dest, Value $srcHt): void
    {
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $tag = (string) self::nextSeq();
        $head = BasicBlockHelper::append($context, 'ht_cow_dup_str_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_cow_dup_str_body_'.$tag);
        $advance = BasicBlockHelper::append($context, 'ht_cow_dup_str_advance_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_cow_dup_str_done_'.$tag);
        $nodeSlot = BasicBlockHelper::entryAlloca($context, $nodePtrType);
        $context->builder->store(
            $context->builder->load($context->builder->structGep($srcHt, $map['strKeys'])),
            $nodeSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $node = $context->builder->load($nodeSlot);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($isNull, $done, $body);

        $context->builder->positionAtEnd($body);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $valField = $context->builder->structGep($node, $nodeMap['value']);
        $elem = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $valField);
        HashTableWriteLlvm::setAtStringKey($context, $dest, $keyStr, $elem);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $next = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($next, $nodeSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function mergeMissingPacked(Context $context, Value $dest, Value $srcHt): void
    {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->builder->load($context->builder->structGep($srcHt, $map['nextFreeElement']));
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);
        $tag = (string) self::nextSeq();
        $head = BasicBlockHelper::append($context, 'ht_cow_union_packed_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_cow_union_packed_body_'.$tag);
        $advance = BasicBlockHelper::append($context, 'ht_cow_union_packed_advance_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_cow_union_packed_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $srcSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $srcHt,
            $idx
        );
        $skip = BasicBlockHelper::append($context, 'ht_cow_union_packed_skip_'.$tag);
        $checkDest = BasicBlockHelper::append($context, 'ht_cow_union_packed_check_'.$tag);
        $context->builder->branchIf($srcSet, $checkDest, $skip);

        $context->builder->positionAtEnd($checkDest);
        $destSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $dest,
            $idx
        );
        $copy = BasicBlockHelper::append($context, 'ht_cow_union_packed_copy_'.$tag);
        $context->builder->branchIf($destSet, $skip, $copy);

        $context->builder->positionAtEnd($copy);
        $elem = HashTableReadLlvm::readIndexedToValueBox($context, $srcHt, $idx);
        HashTableWriteLlvm::setAtIndex($context, $dest, $idx, $elem);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($skip);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function mergeMissingStringKeys(Context $context, Value $dest, Value $srcHt): void
    {
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $tag = (string) self::nextSeq();
        $head = BasicBlockHelper::append($context, 'ht_cow_union_str_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_cow_union_str_body_'.$tag);
        $advance = BasicBlockHelper::append($context, 'ht_cow_union_str_advance_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_cow_union_str_done_'.$tag);
        $nodeSlot = BasicBlockHelper::entryAlloca($context, $nodePtrType);
        $context->builder->store(
            $context->builder->load($context->builder->structGep($srcHt, $map['strKeys'])),
            $nodeSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $node = $context->builder->load($nodeSlot);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($isNull, $done, $body);

        $context->builder->positionAtEnd($body);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $destHas = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
            $dest,
            $keyStr
        );
        $skip = BasicBlockHelper::append($context, 'ht_cow_union_str_skip_'.$tag);
        $copy = BasicBlockHelper::append($context, 'ht_cow_union_str_copy_'.$tag);
        $context->builder->branchIf($destHas, $skip, $copy);

        $context->builder->positionAtEnd($copy);
        $valField = $context->builder->structGep($node, $nodeMap['value']);
        $elem = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $valField);
        HashTableWriteLlvm::setAtStringKey($context, $dest, $keyStr, $elem);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($skip);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $next = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($next, $nodeSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    /** Overlay packed indices from src onto dest (always overwrite when src set). */
    private static function overlayPacked(Context $context, Value $dest, Value $srcHt): void
    {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->builder->load($context->builder->structGep($srcHt, $map['nextFreeElement']));
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);
        $tag = (string) self::nextSeq();
        $head = BasicBlockHelper::append($context, 'ht_cow_replace_packed_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_cow_replace_packed_body_'.$tag);
        $advance = BasicBlockHelper::append($context, 'ht_cow_replace_packed_advance_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_cow_replace_packed_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $srcSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $srcHt,
            $idx
        );
        $skip = BasicBlockHelper::append($context, 'ht_cow_replace_packed_skip_'.$tag);
        $copy = BasicBlockHelper::append($context, 'ht_cow_replace_packed_copy_'.$tag);
        $context->builder->branchIf($srcSet, $copy, $skip);

        $context->builder->positionAtEnd($copy);
        $elem = HashTableReadLlvm::readIndexedToValueBox($context, $srcHt, $idx);
        HashTableWriteLlvm::setAtIndex($context, $dest, $idx, $elem);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($skip);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    /** Overlay string keys from src onto dest (always overwrite). */
    private static function overlayStringKeys(Context $context, Value $dest, Value $srcHt): void
    {
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $tag = (string) self::nextSeq();
        $head = BasicBlockHelper::append($context, 'ht_cow_replace_str_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_cow_replace_str_body_'.$tag);
        $advance = BasicBlockHelper::append($context, 'ht_cow_replace_str_advance_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_cow_replace_str_done_'.$tag);
        $nodeSlot = BasicBlockHelper::entryAlloca($context, $nodePtrType);
        $context->builder->store(
            $context->builder->load($context->builder->structGep($srcHt, $map['strKeys'])),
            $nodeSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $node = $context->builder->load($nodeSlot);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($isNull, $done, $body);

        $context->builder->positionAtEnd($body);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $valField = $context->builder->structGep($node, $nodeMap['value']);
        $elem = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $valField);
        HashTableWriteLlvm::setAtStringKey($context, $dest, $keyStr, $elem);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $next = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($next, $nodeSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }
}
