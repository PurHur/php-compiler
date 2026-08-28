<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\MbConvertEncodingRuntime;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Pure LLVM mb_convert_encoding() when $string is an array (#3222 leftover).
 *
 * NestedJIT of VmMbstring::convertEncodingSourceArray fatals on HashTable walk under thin AOT
 * (peer FilterVarArrayLlvm #34574). String elements use {@see MbConvertEncodingRuntime::convertHelper};
 * other element types are copied verbatim (php-src ext/mbstring/mbstring.c).
 */
final class MbConvertEncodingArrayLlvm
{
    private static int $seq = 0;

    private static function nextSeq(): int
    {
        return ++self::$seq;
    }

    public static function convert(
        Context $context,
        Variable $array,
        Value $toPtr,
        Value $fromPtr
    ): Value {
        MbConvertEncodingRuntime::ensureLinked($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_convert_encoding_array');

        $srcHt = ArrayBuiltinHelper::loadHashTable($context, $array);
        $dest = HashTableHelper::alloc($context);
        self::mapPacked($context, $srcHt, $dest, $toPtr, $fromPtr);
        self::mapStringKeys($context, $srcHt, $dest, $toPtr, $fromPtr);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $dest
        );

        return $ptr;
    }

    private static function mapPacked(
        Context $context,
        Value $src,
        Value $dest,
        Value $toPtr,
        Value $fromPtr
    ): void {
        $tag = (string) self::nextSeq();
        $htMap = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nextFree = $context->builder->load(
            $context->builder->structGep($src, $htMap['nextFreeElement'])
        );
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'mbce_pk_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'mbce_pk_body_'.$tag);
        $take = BasicBlockHelper::append($context, 'mbce_pk_take_'.$tag);
        $next = BasicBlockHelper::append($context, 'mbce_pk_next_'.$tag);
        $done = BasicBlockHelper::append($context, 'mbce_pk_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree),
            $done,
            $body
        );

        $context->builder->positionAtEnd($body);
        $context->builder->branchIf(
            HashTableReadLlvm::packedIndexIsUndefined($context, $src, $idx),
            $next,
            $take
        );

        $context->builder->positionAtEnd($take);
        $valVar = HashTableReadLlvm::readIndexedToValueBox($context, $src, $idx);
        HashTableWriteLlvm::setAtIndex(
            $context,
            $dest,
            $idx,
            self::convertElement($context, $valVar, $toPtr, $fromPtr)
        );
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function mapStringKeys(
        Context $context,
        Value $src,
        Value $dest,
        Value $toPtr,
        Value $fromPtr
    ): void {
        $tag = (string) self::nextSeq();
        $htMap = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrTy = $context->getTypeFromString('__strkey_node__*');
        $nodeSlot = BasicBlockHelper::entryAlloca($context, $nodePtrTy);
        $context->builder->store(
            $context->builder->load($context->builder->structGep($src, $htMap['strKeys'])),
            $nodeSlot
        );

        $head = BasicBlockHelper::append($context, 'mbce_sk_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'mbce_sk_body_'.$tag);
        $next = BasicBlockHelper::append($context, 'mbce_sk_next_'.$tag);
        $done = BasicBlockHelper::append($context, 'mbce_sk_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $node = $context->builder->load($nodeSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrTy->constNull()),
            $done,
            $body
        );

        $context->builder->positionAtEnd($body);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $valField = $context->builder->structGep($node, $nodeMap['value']);
        $valSlot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer($context, $valSlot, $valField);
        $valVar = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $valSlot);
        HashTableWriteLlvm::setAtStringKey(
            $context,
            $dest,
            $keyStr,
            self::convertElement($context, $valVar, $toPtr, $fromPtr)
        );
        $context->builder->store(
            $context->builder->load($context->builder->structGep($node, $nodeMap['next'])),
            $nodeSlot
        );
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function convertElement(
        Context $context,
        Variable $value,
        Value $toPtr,
        Value $fromPtr
    ): Variable {
        $valuePtr = JitValueBox::pointer($context, $value->value);
        $i8 = $context->getTypeFromString('int8');
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $context->structFieldMap['__value__']['type'])
        );
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(VmVariable::TYPE_STRING & 0x7f, false)
        );

        $tag = (string) self::nextSeq();
        $strBlock = BasicBlockHelper::append($context, 'mbce_el_str_'.$tag);
        $copyBlock = BasicBlockHelper::append($context, 'mbce_el_copy_'.$tag);
        $merge = BasicBlockHelper::append($context, 'mbce_el_merge_'.$tag);
        $resultSlot = JitValueBox::alloc($context);
        $context->builder->branchIf($isString, $strBlock, $copyBlock);

        $context->builder->positionAtEnd($strBlock);
        $str = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
        $convertedRaw = JitNestedHelperCoerce::callHelper(
            $context,
            MbConvertEncodingRuntime::convertHelper($context),
            [$str, $toPtr, $fromPtr]
        );
        $converted = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $convertedRaw);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $converted
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $resultSlot),
            $owned
        );
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($copyBlock);
        JitValueBox::copyFromPointer($context, $resultSlot, $valuePtr);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $resultSlot);
    }
}
