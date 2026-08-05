<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\ext\standard\strval;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Call-site LLVM for array_unique() (#27066).
 *
 * Thin AOT NestedJIT of {@see \PHPCompiler\ext\standard\ArrayUniqueJitHelper} compiles after
 * the castObjectToString fix but segfaults at runtime (same iterateKeyed NestedJIT class as
 * ArrayFlip #21981 / #26970). Walk hashtables with HashTableHelper / value-box APIs instead.
 *
 * SSOT for VM remains {@see \PHPCompiler\ext\standard\ArrayUniqueJitHelper}.
 * php-src: ext/standard/array.c — php_array_unique()
 */
final class ArrayUniqueLlvm
{
    private static int $seq = 0;

    public static function uniqueHashTable(Context $context, Value $src, int $flags): Value
    {
        $sortType = $flags & ~StdlibConstants::SORT_FLAG_CASE;
        if (
            StdlibConstants::SORT_STRING !== $sortType
            && StdlibConstants::SORT_NUMERIC !== $sortType
            && StdlibConstants::SORT_REGULAR !== $sortType
        ) {
            throw new \LogicException('array_unique() flags are not supported in this compiler build');
        }
        $dest = HashTableHelper::alloc($context);
        if (StdlibConstants::SORT_REGULAR === $sortType) {
            self::uniqueRegular($context, $src, $dest);
        } else {
            self::uniqueBySeenKey($context, $src, $dest, $sortType);
        }

        return $dest;
    }

    /** SORT_STRING / SORT_NUMERIC — dedupe via side hashtable of signature strings. */
    private static function uniqueBySeenKey(Context $context, Value $src, Value $dest, int $sortType): void
    {
        $seen = HashTableHelper::alloc($context);
        self::uniquePackedBySeen($context, $src, $dest, $seen, $sortType);
        self::uniqueStringKeysBySeen($context, $src, $dest, $seen, $sortType);
    }

    private static function uniquePackedBySeen(
        Context $context,
        Value $src,
        Value $dest,
        Value $seen,
        int $sortType
    ): void {
        $tag = (string) (++self::$seq);
        $htMap = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $nextFree = $context->builder->load($context->builder->structGep($src, $htMap['nextFreeElement']));
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'array_unique_pk_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'array_unique_pk_body_'.$tag);
        $keep = BasicBlockHelper::append($context, 'array_unique_pk_keep_'.$tag);
        $add = BasicBlockHelper::append($context, 'array_unique_pk_add_'.$tag);
        $next = BasicBlockHelper::append($context, 'array_unique_pk_next_'.$tag);
        $done = BasicBlockHelper::append($context, 'array_unique_pk_done_'.$tag);
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
        $context->builder->branchIf($isSet, $keep, $next);

        $context->builder->positionAtEnd($keep);
        $valVar = HashTableReadLlvm::readIndexedToValueBox($context, $src, $idx);
        $sig = self::signatureString($context, $valVar, $sortType);
        $dup = HashTableReadLlvm::offsetIsSetValueBoxKey(
            $context,
            $seen,
            self::stringValueBoxFromString($context, $sig)
        );
        $context->builder->branchIf($dup, $next, $add);

        $context->builder->positionAtEnd($add);
        HashTableHelper::setAtStringKey($context, $seen, $sig, self::intOneBox($context));
        HashTableHelper::setAtIndex($context, $dest, $idx, $valVar);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function uniqueStringKeysBySeen(
        Context $context,
        Value $src,
        Value $dest,
        Value $seen,
        int $sortType
    ): void {
        $tag = (string) (++self::$seq);
        $htMap = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrTy = $context->getTypeFromString('__strkey_node__*');

        $nodeSlot = BasicBlockHelper::entryAlloca($context, $nodePtrTy);
        $headNode = $context->builder->load($context->builder->structGep($src, $htMap['strKeys']));
        $context->builder->store($headNode, $nodeSlot);

        $head = BasicBlockHelper::append($context, 'array_unique_sk_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'array_unique_sk_body_'.$tag);
        $add = BasicBlockHelper::append($context, 'array_unique_sk_add_'.$tag);
        $next = BasicBlockHelper::append($context, 'array_unique_sk_next_'.$tag);
        $done = BasicBlockHelper::append($context, 'array_unique_sk_done_'.$tag);
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
        $sig = self::signatureString($context, $valVar, $sortType);
        $dup = HashTableReadLlvm::offsetIsSetValueBoxKey(
            $context,
            $seen,
            self::stringValueBoxFromString($context, $sig)
        );
        $context->builder->branchIf($dup, $next, $add);

        $context->builder->positionAtEnd($add);
        HashTableHelper::setAtStringKey($context, $seen, $sig, self::intOneBox($context));
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $keyStr);
        HashTableHelper::setAtStringKey($context, $dest, $owned, $valVar);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $nodeSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    /** SORT_REGULAR — keep first entry that is not loosely equal to any already kept. */
    private static function uniqueRegular(Context $context, Value $src, Value $dest): void
    {
        self::uniquePackedRegular($context, $src, $dest);
        self::uniqueStringKeysRegular($context, $src, $dest);
    }

    private static function uniquePackedRegular(Context $context, Value $src, Value $dest): void
    {
        $tag = (string) (++self::$seq);
        $htMap = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $nextFree = $context->builder->load($context->builder->structGep($src, $htMap['nextFreeElement']));
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'array_unique_reg_pk_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'array_unique_reg_pk_body_'.$tag);
        $keep = BasicBlockHelper::append($context, 'array_unique_reg_pk_keep_'.$tag);
        $add = BasicBlockHelper::append($context, 'array_unique_reg_pk_add_'.$tag);
        $next = BasicBlockHelper::append($context, 'array_unique_reg_pk_next_'.$tag);
        $done = BasicBlockHelper::append($context, 'array_unique_reg_pk_done_'.$tag);
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
        $context->builder->branchIf($isSet, $keep, $next);

        $context->builder->positionAtEnd($keep);
        $valVar = HashTableReadLlvm::readIndexedToValueBox($context, $src, $idx);
        $dup = self::destContainsLooseEqual($context, $dest, $valVar);
        $context->builder->branchIf($dup, $next, $add);

        $context->builder->positionAtEnd($add);
        HashTableHelper::setAtIndex($context, $dest, $idx, $valVar);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function uniqueStringKeysRegular(Context $context, Value $src, Value $dest): void
    {
        $tag = (string) (++self::$seq);
        $htMap = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrTy = $context->getTypeFromString('__strkey_node__*');

        $nodeSlot = BasicBlockHelper::entryAlloca($context, $nodePtrTy);
        $headNode = $context->builder->load($context->builder->structGep($src, $htMap['strKeys']));
        $context->builder->store($headNode, $nodeSlot);

        $head = BasicBlockHelper::append($context, 'array_unique_reg_sk_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'array_unique_reg_sk_body_'.$tag);
        $add = BasicBlockHelper::append($context, 'array_unique_reg_sk_add_'.$tag);
        $next = BasicBlockHelper::append($context, 'array_unique_reg_sk_next_'.$tag);
        $done = BasicBlockHelper::append($context, 'array_unique_reg_sk_done_'.$tag);
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
        $dup = self::destContainsLooseEqual($context, $dest, $valVar);
        $context->builder->branchIf($dup, $next, $add);

        $context->builder->positionAtEnd($add);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $keyStr);
        HashTableHelper::setAtStringKey($context, $dest, $owned, $valVar);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $nodeSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function signatureString(Context $context, Variable $valVar, int $sortType): Value
    {
        $valPtr = JitValueBox::valuePtrFromVariable($context, $valVar);
        // SORT_NUMERIC: coerce a *copy* via __value__toNumeric so the kept entry stays pristine.
        if (StdlibConstants::SORT_NUMERIC === $sortType) {
            $tmp = JitValueBox::alloc($context);
            JitValueBox::copyFromPointer($context, $tmp, $valPtr);
            $tmpPtr = JitValueBox::pointer($context, $tmp);
            $context->builder->call($context->lookupFunction('__value__toNumeric'), $tmpPtr);
            // toNumeric may leave a long or double — normalize via strval of the coerced box.
            return $context->builder->call(
                $context->lookupFunction('__string__separate'),
                (new strval())->valueToString($context, $tmpPtr)
            );
        }

        return $context->builder->call(
            $context->lookupFunction('__string__separate'),
            (new strval())->valueToString($context, $valPtr)
        );
    }

    private static function stringValueBoxFromString(Context $context, Value $str): Variable
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $str
        );

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
    }

    private static function intOneBox(Context $context): Variable
    {
        $slot = JitValueBox::alloc($context);
        $i64 = $context->getTypeFromString('int64');
        JitValueBox::writeLong($context, $slot, $i64->constInt(1, false));

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
    }

    /**
     * True when $needle is loosely equal to any packed or string-key entry already in $dest.
     */
    private static function destContainsLooseEqual(Context $context, Value $dest, Variable $needle): Value
    {
        $tag = (string) (++self::$seq);
        $i1 = $context->getTypeFromString('int1');
        $result = BasicBlockHelper::entryAlloca($context, $i1);
        $context->builder->store($i1->constInt(0, false), $result);

        $htMap = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nextFree = $context->builder->load($context->builder->structGep($dest, $htMap['nextFreeElement']));
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'array_unique_eq_pk_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'array_unique_eq_pk_body_'.$tag);
        $cmp = BasicBlockHelper::append($context, 'array_unique_eq_pk_cmp_'.$tag);
        $hit = BasicBlockHelper::append($context, 'array_unique_eq_pk_hit_'.$tag);
        $next = BasicBlockHelper::append($context, 'array_unique_eq_pk_next_'.$tag);
        $strInit = BasicBlockHelper::append($context, 'array_unique_eq_sk_init_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $strInit, $body);

        $context->builder->positionAtEnd($body);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $dest,
            $idx
        );
        $context->builder->branchIf($isSet, $cmp, $next);

        $context->builder->positionAtEnd($cmp);
        $have = HashTableReadLlvm::readIndexedToValueBox($context, $dest, $idx);
        $eq = JitValueCompare::looseEqualOperands($context, $needle, $have);
        $context->builder->branchIf($eq, $hit, $next);

        $context->builder->positionAtEnd($hit);
        $context->builder->store($i1->constInt(1, false), $result);
        $done = BasicBlockHelper::append($context, 'array_unique_eq_done_'.$tag);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($strInit);
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrTy = $context->getTypeFromString('__strkey_node__*');
        $nodeSlot = BasicBlockHelper::entryAlloca($context, $nodePtrTy);
        $headNode = $context->builder->load($context->builder->structGep($dest, $htMap['strKeys']));
        $context->builder->store($headNode, $nodeSlot);
        $skHead = BasicBlockHelper::append($context, 'array_unique_eq_sk_head_'.$tag);
        $skBody = BasicBlockHelper::append($context, 'array_unique_eq_sk_body_'.$tag);
        $skHit = BasicBlockHelper::append($context, 'array_unique_eq_sk_hit_'.$tag);
        $skNext = BasicBlockHelper::append($context, 'array_unique_eq_sk_next_'.$tag);
        $context->builder->branch($skHead);

        $context->builder->positionAtEnd($skHead);
        $node = $context->builder->load($nodeSlot);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrTy->constNull());
        $context->builder->branchIf($isNull, $done, $skBody);

        $context->builder->positionAtEnd($skBody);
        $valField = $context->builder->structGep($node, $nodeMap['value']);
        $valSlot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer($context, $valSlot, $valField);
        $haveSk = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $valSlot);
        $eqSk = JitValueCompare::looseEqualOperands($context, $needle, $haveSk);
        $context->builder->branchIf($eqSk, $skHit, $skNext);

        $context->builder->positionAtEnd($skHit);
        $context->builder->store($i1->constInt(1, false), $result);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($skNext);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $nodeSlot);
        $context->builder->branch($skHead);

        $context->builder->positionAtEnd($done);

        return $context->builder->load($result);
    }
}
