<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Call-site LLVM for array_flip() (#26970).
 *
 * Thin AOT NestedJIT of ArrayFlipJitHelper fatals on HashTable::iterateKeyed (#21981);
 * this walks the source hashtable with HashTableHelper / value-box APIs (no NestedJIT).
 *
 * SSOT for VM remains {@see \PHPCompiler\ext\standard\VmArray::flip()}.
 * php-src: ext/standard/array.c — php_array_flip()
 */
final class ArrayFlipLlvm
{
    private static int $seq = 0;

    public static function flipHashTable(Context $context, Value $src): Value
    {
        $dest = HashTableHelper::alloc($context);
        self::flipPackedEntries($context, $src, $dest);
        self::flipStringEntries($context, $src, $dest);

        return $dest;
    }

    private static function flipPackedEntries(Context $context, Value $src, Value $dest): void
    {
        $tag = (string) (++self::$seq);
        $htMap = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $nextFree = $context->builder->load($context->builder->structGep($src, $htMap['nextFreeElement']));
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'array_flip_pk_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'array_flip_pk_body_'.$tag);
        $flip = BasicBlockHelper::append($context, 'array_flip_pk_flip_'.$tag);
        $next = BasicBlockHelper::append($context, 'array_flip_pk_next_'.$tag);
        $done = BasicBlockHelper::append($context, 'array_flip_pk_done_'.$tag);
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
        $context->builder->branchIf($isSet, $flip, $next);

        $context->builder->positionAtEnd($flip);
        $valVar = HashTableReadLlvm::readIndexedToValueBox($context, $src, $idx);
        $oldKey = self::longValueBox($context, $context->builder->zExt($idx, $i64));
        self::storeFlipped($context, $dest, $valVar, $oldKey);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function flipStringEntries(Context $context, Value $src, Value $dest): void
    {
        $tag = (string) (++self::$seq);
        $htMap = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrTy = $context->getTypeFromString('__strkey_node__*');
        $strMap = $context->structFieldMap['__string__'];

        $nodeSlot = BasicBlockHelper::entryAlloca($context, $nodePtrTy);
        $headNode = $context->builder->load($context->builder->structGep($src, $htMap['strKeys']));
        $context->builder->store($headNode, $nodeSlot);

        $head = BasicBlockHelper::append($context, 'array_flip_sk_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'array_flip_sk_body_'.$tag);
        $next = BasicBlockHelper::append($context, 'array_flip_sk_next_'.$tag);
        $done = BasicBlockHelper::append($context, 'array_flip_sk_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $node = $context->builder->load($nodeSlot);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrTy->constNull());
        $context->builder->branchIf($isNull, $done, $body);

        $context->builder->positionAtEnd($body);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $keyLen = $context->builder->load($context->builder->structGep($keyStr, $strMap['length']));
        $keyPtr = $context->builder->structGep($keyStr, $strMap['value']);
        $oldKey = self::stringValueBox($context, $keyPtr, $keyLen);
        $valField = $context->builder->structGep($node, $nodeMap['value']);
        $valSlot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer($context, $valSlot, $valField);
        $valVar = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $valSlot);
        self::storeFlipped($context, $dest, $valVar, $oldKey);

        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $nodeSlot);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    /**
     * php_array_flip: new key = old value (int|string only); new value = old key.
     */
    private static function storeFlipped(
        Context $context,
        Value $dest,
        Variable $valVar,
        Variable $oldKey
    ): void {
        $tag = (string) (++self::$seq);
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        $valPtr = JitValueBox::valuePtrFromVariable($context, $valVar);
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load($context->builder->structGep($valPtr, $valueMap['type']));
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));

        $stringBb = BasicBlockHelper::append($context, 'array_flip_store_str_'.$tag);
        $longBb = BasicBlockHelper::append($context, 'array_flip_store_long_'.$tag);
        $skipBb = BasicBlockHelper::append($context, 'array_flip_store_skip_'.$tag);
        $done = BasicBlockHelper::append($context, 'array_flip_store_done_'.$tag);

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

        $afterString = BasicBlockHelper::append($context, 'array_flip_store_after_str_'.$tag);
        $context->builder->branchIf($isString, $stringBb, $afterString);

        $context->builder->positionAtEnd($stringBb);
        $newKeyStr = $context->builder->call($context->lookupFunction('__value__readString'), $valPtr);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $newKeyStr);
        HashTableHelper::setAtStringKey($context, $dest, $owned, $oldKey);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterString);
        $context->builder->branchIf($isLong, $longBb, $skipBb);

        $context->builder->positionAtEnd($longBb);
        $newKeyIdx = $context->builder->truncOrBitCast(
            $context->builder->call($context->lookupFunction('__value__readLong'), $valPtr),
            $sizeT
        );
        HashTableHelper::setAtIndex($context, $dest, $newKeyIdx, $oldKey);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($skipBb);
        self::emitFlipSkipWarning($context);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    private static function longValueBox(Context $context, Value $long): Variable
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $slot, $long);

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

    private static function emitFlipSkipWarning(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $msg = $context->builder->pointerCast(
            $context->constantFromString(
                'array_flip(): Can only flip string and integer values, entry skipped'
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
