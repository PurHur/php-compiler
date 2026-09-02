<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin\Type;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM;
use PHPLLVM\Builder;

/**
 * Destroy {@see __hashtable__} contents before the header is freed (#36215).
 *
 * php-src: Zend/zend_hash.c zend_array_destroy — delref elements, free buckets, free header.
 */
final class HashTableDtorLlvm
{
    public static function register(HashTable $hashTable): void
    {
        $context = $hashTable->jitContext();
        $void = $context->getTypeFromString('void');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $ft = $context->context->functionType($void, false, $htPtr);
        $fn = $context->module->addFunction('__hashtable__dtor', $ft);
        $context->registerFunction('__hashtable__dtor', $fn);
    }

    public static function implement(HashTable $hashTable): void
    {
        $context = $hashTable->jitContext();
        $fn = $context->module->getNamedFunction('__hashtable__dtor');
        if (null === $fn || $fn->countBasicBlocks() > 0) {
            return;
        }

        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $ht = $fn->getParam(0);

        self::destroyPackedValues($context, $fn, $entry, $ht);
        self::destroyStrKeyChain($context, $fn, $ht);
        self::destroyObjKeyChain($context, $fn, $ht);
        self::freeValuesArray($context, $ht);

        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function destroyPackedValues(
        Context $context,
        PHPLLVM\Value\Function_ $fn,
        PHPLLVM\BasicBlock $entryBlock,
        PHPLLVM\Value $ht
    ): void {
        $map = $context->structFieldMap['__hashtable__'];
        $valueDelref = $context->lookupFunction('__value__valueDelref');
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $i8 = $context->getTypeFromString('int8');
        $undef = $i8->constInt(VmVariable::TYPE_UNDEFINED & 0xff, false);
        $nullType = $i8->constInt(VmVariable::TYPE_NULL, false);

        $nextFree = $context->builder->load(
            $context->builder->structGep($ht, $map['nextFreeElement'])
        );
        $values = $context->builder->load(
            $context->builder->structGep($ht, $map['values'])
        );
        $valuesNull = $context->builder->icmp(
            Builder::INT_EQ,
            $values,
            $values->typeOf()->constNull()
        );
        $skip = $fn->appendBasicBlock('ht_dtor_packed_skip');
        $loopHead = $fn->appendBasicBlock('ht_dtor_packed_head');
        $loopBody = $fn->appendBasicBlock('ht_dtor_packed_body');
        $loopDone = $fn->appendBasicBlock('ht_dtor_packed_done');
        $context->builder->branchIf($valuesNull, $skip, $loopHead);

        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->phi($sizeT);
        $idx->addIncoming($zero, $entryBlock);
        $doneLoop = $context->builder->icmp(Builder::INT_UGE, $idx, $nextFree);
        $context->builder->branchIf($doneLoop, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $entryPtr = $context->builder->inBoundsGep($values, $idx);
        $typeByte = $context->builder->load(
            $context->builder->structGep($entryPtr, $context->structFieldMap['__value__']['type'])
        );
        $isNull = $context->builder->icmp(Builder::INT_EQ, $typeByte, $nullType);
        $isUndef = $context->builder->icmp(Builder::INT_EQ, $typeByte, $undef);
        $isLive = $context->builder->not($context->builder->or($isNull, $isUndef));
        $release = $fn->appendBasicBlock('ht_dtor_packed_release');
        $advance = $fn->appendBasicBlock('ht_dtor_packed_advance');
        $context->builder->branchIf($isLive, $release, $advance);

        $context->builder->positionAtEnd($release);
        $context->builder->call($valueDelref, $entryPtr);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $nextIdx = $context->builder->addNoSignedWrap($idx, $one);
        $context->builder->branch($loopHead);
        $idx->addIncoming($nextIdx, $advance);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->branch($skip);
        $context->builder->positionAtEnd($skip);
    }

    private static function destroyStrKeyChain(
        Context $context,
        PHPLLVM\Value\Function_ $fn,
        PHPLLVM\Value $ht
    ): void {
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $headSlot = $context->builder->structGep($ht, $map['strKeys']);
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $refVirtual = $context->getTypeFromString('__ref__virtual*');
        $delref = $context->lookupFunction('__ref__delref');
        $valueDelref = $context->lookupFunction('__value__valueDelref');

        $currentSlot = $context->builder->alloca($nodePtrType, 1, 'ht_dtor_strkey_cur');
        $context->builder->store($context->builder->load($headSlot), $currentSlot);

        $done = $fn->appendBasicBlock('ht_dtor_strkey_done');
        $loopHead = $fn->appendBasicBlock('ht_dtor_strkey_head');
        $loopBody = $fn->appendBasicBlock('ht_dtor_strkey_body');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $node = $context->builder->load($currentSlot);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($isNull, $done, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $key = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $context->builder->call(
            $delref,
            $context->builder->pointerCast($key, $refVirtual)
        );
        $context->builder->call(
            $valueDelref,
            $context->builder->structGep($node, $nodeMap['value'])
        );
        $context->builder->store($nextNode, $currentSlot);
        $context->memory->free($node);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($done);
        $context->builder->store($nodePtrType->constNull(), $headSlot);
    }

    private static function destroyObjKeyChain(
        Context $context,
        PHPLLVM\Value\Function_ $fn,
        PHPLLVM\Value $ht
    ): void {
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__objkey_node__'];
        $headSlot = $context->builder->structGep($ht, $map['objKeys']);
        $nodePtrType = $context->getTypeFromString('__objkey_node__*');
        $refVirtual = $context->getTypeFromString('__ref__virtual*');
        $delref = $context->lookupFunction('__ref__delref');
        $valueDelref = $context->lookupFunction('__value__valueDelref');

        $currentSlot = $context->builder->alloca($nodePtrType, 1, 'ht_dtor_objkey_cur');
        $context->builder->store($context->builder->load($headSlot), $currentSlot);

        $done = $fn->appendBasicBlock('ht_dtor_objkey_done');
        $loopHead = $fn->appendBasicBlock('ht_dtor_objkey_head');
        $loopBody = $fn->appendBasicBlock('ht_dtor_objkey_body');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $node = $context->builder->load($currentSlot);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($isNull, $done, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $key = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $context->builder->call(
            $delref,
            $context->builder->pointerCast($key, $refVirtual)
        );
        $context->builder->call(
            $valueDelref,
            $context->builder->structGep($node, $nodeMap['value'])
        );
        $context->builder->store($nextNode, $currentSlot);
        $context->memory->free($node);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($done);
        $context->builder->store($nodePtrType->constNull(), $headSlot);
    }

    private static function freeValuesArray(Context $context, PHPLLVM\Value $ht): void
    {
        $map = $context->structFieldMap['__hashtable__'];
        $valuesPtr = $context->builder->structGep($ht, $map['values']);
        $values = $context->builder->load($valuesPtr);
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $values,
            $values->typeOf()->constNull()
        );
        $parentFn = $context->builder->getInsertBlock()->getParent();
        assert($parentFn instanceof PHPLLVM\Value\Function_);
        $freeBlock = $parentFn->appendBasicBlock('ht_dtor_free_values');
        $after = $parentFn->appendBasicBlock('ht_dtor_after_values');
        $context->builder->branchIf($isNull, $after, $freeBlock);
        $context->builder->positionAtEnd($freeBlock);
        $context->memory->free($values);
        $context->builder->store($values->typeOf()->constNull(), $valuesPtr);
        $context->builder->branch($after);
        $context->builder->positionAtEnd($after);
    }
}
