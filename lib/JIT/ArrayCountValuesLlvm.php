<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Call-site LLVM for array_count_values() (#27213).
 *
 * Thin AOT NestedJIT of {@see \PHPCompiler\ext\standard\ArrayCountValuesJitHelper}
 * aborts inside the helper body (peer array_flip #26970 / iterateKeyed). Walk the
 * source hashtable with HashTableHelper / value-box APIs instead.
 *
 * SSOT for VM remains {@see \PHPCompiler\ext\standard\VmArray::countValues()}.
 * php-src: ext/standard/array.c — php_array_count_values()
 */
final class ArrayCountValuesLlvm
{
    private static int $seq = 0;

    public static function countValuesHashTable(Context $context, Value $src): Value
    {
        $dest = HashTableHelper::alloc($context);
        self::countPackedEntries($context, $src, $dest);
        self::countStringEntries($context, $src, $dest);

        return $dest;
    }

    private static function countPackedEntries(Context $context, Value $src, Value $dest): void
    {
        $tag = (string) (++self::$seq);
        $htMap = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $nextFree = $context->builder->load($context->builder->structGep($src, $htMap['nextFreeElement']));
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'array_count_values_pk_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'array_count_values_pk_body_'.$tag);
        $count = BasicBlockHelper::append($context, 'array_count_values_pk_count_'.$tag);
        $next = BasicBlockHelper::append($context, 'array_count_values_pk_next_'.$tag);
        $done = BasicBlockHelper::append($context, 'array_count_values_pk_done_'.$tag);
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
        $context->builder->branchIf($isSet, $count, $next);

        $context->builder->positionAtEnd($count);
        $valVar = HashTableReadLlvm::readIndexedToValueBox($context, $src, $idx);
        self::incrementForValue($context, $dest, $valVar);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function countStringEntries(Context $context, Value $src, Value $dest): void
    {
        $tag = (string) (++self::$seq);
        $htMap = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrTy = $context->getTypeFromString('__strkey_node__*');

        $nodeSlot = BasicBlockHelper::entryAlloca($context, $nodePtrTy);
        $headNode = $context->builder->load($context->builder->structGep($src, $htMap['strKeys']));
        $context->builder->store($headNode, $nodeSlot);

        $head = BasicBlockHelper::append($context, 'array_count_values_sk_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'array_count_values_sk_body_'.$tag);
        $next = BasicBlockHelper::append($context, 'array_count_values_sk_next_'.$tag);
        $done = BasicBlockHelper::append($context, 'array_count_values_sk_done_'.$tag);
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
        self::incrementForValue($context, $dest, $valVar);

        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $nodeSlot);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    /**
     * php_array_count_values: count int|string values only; warn-and-skip others.
     */
    private static function incrementForValue(Context $context, Value $dest, Variable $valVar): void
    {
        $tag = (string) (++self::$seq);
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $oneLong = $i64->constInt(1, false);
        $valPtr = JitValueBox::valuePtrFromVariable($context, $valVar);
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load($context->builder->structGep($valPtr, $valueMap['type']));
        // Mask IS_REFCOUNTED so VM string tags (4|0x80) still match (#21921).
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));

        $stringBb = BasicBlockHelper::append($context, 'array_count_values_val_str_'.$tag);
        $longBb = BasicBlockHelper::append($context, 'array_count_values_val_long_'.$tag);
        $skipBb = BasicBlockHelper::append($context, 'array_count_values_val_skip_'.$tag);
        $done = BasicBlockHelper::append($context, 'array_count_values_val_done_'.$tag);

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_STRING & 0x7f, false)
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_NATIVE_LONG & 0x7f, false)
        );

        $afterString = BasicBlockHelper::append($context, 'array_count_values_after_str_'.$tag);
        $context->builder->branchIf($isString, $stringBb, $afterString);

        $context->builder->positionAtEnd($stringBb);
        $keyStr = $context->builder->call($context->lookupFunction('__value__readString'), $valPtr);
        $ownedKey = $context->builder->call($context->lookupFunction('__string__separate'), $keyStr);
        $exists = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
            $dest,
            $ownedKey
        );
        $strNew = BasicBlockHelper::append($context, 'array_count_values_str_new_'.$tag);
        $strInc = BasicBlockHelper::append($context, 'array_count_values_str_inc_'.$tag);
        $strDone = BasicBlockHelper::append($context, 'array_count_values_str_done_'.$tag);
        $context->builder->branchIf($exists, $strInc, $strNew);

        $context->builder->positionAtEnd($strNew);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $dest,
            $ownedKey,
            $oneLong
        );
        $context->builder->branch($strDone);

        $context->builder->positionAtEnd($strInc);
        $existing = $context->builder->call(
            $context->lookupFunction('__hashtable__peekStringKeyValue'),
            $dest,
            $ownedKey
        );
        $oldCount = $context->builder->call($context->lookupFunction('__value__readLong'), $existing);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $dest,
            $ownedKey,
            $context->builder->addNoSignedWrap($oldCount, $oneLong)
        );
        $context->builder->branch($strDone);

        $context->builder->positionAtEnd($strDone);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterString);
        $context->builder->branchIf($isLong, $longBb, $skipBb);

        $context->builder->positionAtEnd($skipBb);
        self::emitCountValuesSkipWarning($context);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($longBb);
        $keyIdx = $context->builder->truncOrBitCast(
            $context->builder->call($context->lookupFunction('__value__readLong'), $valPtr),
            $sizeT
        );
        $existsIdx = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $dest,
            $keyIdx
        );
        $longNew = BasicBlockHelper::append($context, 'array_count_values_long_new_'.$tag);
        $longInc = BasicBlockHelper::append($context, 'array_count_values_long_inc_'.$tag);
        $longDone = BasicBlockHelper::append($context, 'array_count_values_long_done_'.$tag);
        $context->builder->branchIf($existsIdx, $longInc, $longNew);

        $context->builder->positionAtEnd($longNew);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setLongAt'),
            $dest,
            $keyIdx,
            $oneLong
        );
        $context->builder->branch($longDone);

        $context->builder->positionAtEnd($longInc);
        $oldCountIdx = $context->builder->call(
            $context->lookupFunction('__hashtable__readLongAt'),
            $dest,
            $keyIdx
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setLongAt'),
            $dest,
            $keyIdx,
            $context->builder->addNoSignedWrap($oldCountIdx, $oneLong)
        );
        $context->builder->branch($longDone);

        $context->builder->positionAtEnd($longDone);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    private static function emitCountValuesSkipWarning(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $msg = $context->builder->pointerCast(
            $context->constantFromString(
                'array_count_values(): Can only count string and integer values, entry skipped'
            ),
            $i8p
        );
        // strlen(3) via LibcExtern::ensureStrlenDecl after always-on drop (#32068).
        LibcExtern::ensureStrlenDecl($context);
        $msgLen = $context->builder->call($context->lookupFunction('strlen'), $msg);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msg,
            $msgLen,
            $i32->constInt(2, false),
            $context->builder->pointerCast($context->constantFromString(''), $i8p),
            $i32->constInt(0, false)
        );
    }
}
