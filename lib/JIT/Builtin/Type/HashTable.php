<?php

declare(strict_types=1);

/**
 * Packed-list __hashtable__ for JIT/AOT (integer indices; subset of PHP array semantics).
 */

namespace PHPCompiler\JIT\Builtin\Type;

use PHPCompiler\JIT\Builtin\Refcount;
use PHPCompiler\JIT\Builtin\Type;
use PHPCompiler\JIT\Variable;
use PHPLLVM;
use PHPLLVM\Builder;

class HashTable extends Type
{
    public PHPLLVM\Type $pointer;

    public function register(): void
    {
        $struct = $this->context->context->namedStructType('__hashtable__');
        $this->context->registerType('__hashtable__', $struct);
        $this->context->registerType('__hashtable__*', $struct->pointerType(0));
        $struct->setBody(
            false,
            $this->context->getTypeFromString('__ref__'),
            $this->context->getTypeFromString('size_t'),
            $this->context->getTypeFromString('size_t'),
            $this->context->getTypeFromString('size_t'),
            $this->context->getTypeFromString('__value__')->pointerType(0),
        );
        $this->context->structFieldMap['__hashtable__'] = [
            'ref' => 0,
            'numElements' => 1,
            'nextFreeElement' => 2,
            'capacity' => 3,
            'values' => 4,
        ];

        $this->registerFn('__hashtable__alloc', '__hashtable__*', []);
        $this->registerFn('__hashtable__grow', 'void', ['__hashtable__*', 'size_t']);
        $this->registerFn('__hashtable__setLongAt', 'void', ['__hashtable__*', 'size_t', 'int64']);
        $this->registerFn('__hashtable__setStringAt', 'void', ['__hashtable__*', 'size_t', '__string__*']);
        $this->registerFn('__hashtable__readLongAt', 'int64', ['__hashtable__*', 'size_t']);
        $this->registerFn('__hashtable__getNumElements', 'size_t', ['__hashtable__*']);
        $this->registerFn('__hashtable__offsetIsSet', 'int1', ['__hashtable__*', 'size_t']);

        $this->pointer = $this->context->getTypeFromString('__hashtable__*');
    }

    /**
     * @param list<string> $paramTypes
     */
    private function registerFn(string $name, string $returnType, array $paramTypes): void
    {
        $params = array_map(fn (string $t) => $this->context->getTypeFromString($t), $paramTypes);
        $ft = $this->context->context->functionType(
            $this->context->getTypeFromString($returnType),
            false,
            ...$params
        );
        $fn = $this->context->module->addFunction($name, $ft);
        $fn->addAttributeAtIndex(PHPLLVM\Attribute::INDEX_FUNCTION, $this->context->attributes['alwaysinline']);
        $this->context->registerFunction($name, $fn);
    }

    public function implement(): void
    {
        $this->implementAlloc();
        $this->implementGrow();
        $this->implementSetLongAt();
        $this->implementSetStringAt();
        $this->implementReadLongAt();
        $this->implementGetNumElements();
        $this->implementOffsetIsSet();
    }

    private function implementAlloc(): void
    {
        $fn = $this->context->lookupFunction('__hashtable__alloc');
        $block = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($block);
        $ht = $this->context->memory->malloc($this->context->getTypeFromString('__hashtable__'));
        $map = $this->context->structFieldMap['__hashtable__'];
        $sizeT = $this->context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        foreach (['numElements', 'nextFreeElement', 'capacity'] as $field) {
            $this->context->builder->store(
                $zero,
                $this->context->builder->structGep($ht, $map[$field])
            );
        }
        $nullValues = $this->context->getTypeFromString('__value__*')->constNull();
        $this->context->builder->store(
            $nullValues,
            $this->context->builder->structGep($ht, $map['values'])
        );
        $typeinfo = $this->context->getTypeFromString('int32')->constInt(
            Refcount::TYPE_INFO_TYPE_MASKED_ARRAY | Refcount::TYPE_INFO_REFCOUNTED,
            false
        );
        $ref = $this->context->builder->pointerCast(
            $ht,
            $this->context->getTypeFromString('__ref__virtual*')
        );
        $this->context->builder->call(
            $this->context->lookupFunction('__ref__init'),
            $typeinfo,
            $ref
        );
        $this->context->builder->returnValue($ht);
    }

    private function implementGrow(): void
    {
        $fn = $this->context->lookupFunction('__hashtable__grow');
        $entry = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($entry);
        $ht = $fn->getParam(0);
        $minCap = $fn->getParam(1);
        $map = $this->context->structFieldMap['__hashtable__'];
        $sizeT = $this->context->getTypeFromString('size_t');
        $cap = $this->context->builder->load($this->context->builder->structGep($ht, $map['capacity']));
        $needsGrow = $this->context->builder->icmp(Builder::INT_ULT, $cap, $minCap);
        $done = $fn->appendBasicBlock('grow_done');
        $grow = $fn->appendBasicBlock('grow_work');
        $this->context->builder->branchIf($needsGrow, $grow, $done);

        $this->context->builder->positionAtEnd($grow);
        $eight = $sizeT->constInt(8, false);
        $two = $sizeT->constInt(2, false);
        $newCap = $this->context->builder->select(
            $this->context->builder->icmp(Builder::INT_ULT, $cap, $eight),
            $eight,
            $cap
        );
        $capSlot = $this->context->builder->alloca($sizeT, 1, 'grow_new_cap');
        $this->context->builder->store($newCap, $capSlot);
        $loopHead = $fn->appendBasicBlock('grow_loop_head');
        $loopBody = $fn->appendBasicBlock('grow_loop_body');
        $allocBlock = $fn->appendBasicBlock('grow_alloc');
        $this->context->builder->branch($loopHead);

        $this->context->builder->positionAtEnd($loopHead);
        $nc = $this->context->builder->load($capSlot);
        $enough = $this->context->builder->icmp(Builder::INT_UGE, $nc, $minCap);
        $this->context->builder->branchIf($enough, $allocBlock, $loopBody);

        $this->context->builder->positionAtEnd($loopBody);
        $this->context->builder->store(
            $this->context->builder->mulNoSignedWrap($nc, $two),
            $capSlot
        );
        $this->context->builder->branch($loopHead);

        $this->context->builder->positionAtEnd($allocBlock);
        $nc = $this->context->builder->load($capSlot);
        $valueSize = $sizeT->constInt(16, false);
        $bytes = $this->context->builder->mulNoSignedWrap($nc, $valueSize);
        $valuesPtr = $this->context->builder->load($this->context->builder->structGep($ht, $map['values']));
        $i8p = $this->context->getTypeFromString('int8*');
        $valuePp = $this->context->getTypeFromString('__value__*');
        $isNull = $this->context->builder->icmp(
            Builder::INT_EQ,
            $valuesPtr,
            $valuePp->constNull()
        );
        $mallocBb = $fn->appendBasicBlock('grow_malloc');
        $reallocBb = $fn->appendBasicBlock('grow_realloc');
        $afterBb = $fn->appendBasicBlock('grow_after');
        $this->context->builder->branchIf($isNull, $mallocBb, $reallocBb);

        $this->context->builder->positionAtEnd($mallocBb);
        $malloced = $this->context->builder->call($this->context->lookupFunction('__mm__malloc'), $bytes);
        $this->context->builder->branch($afterBb);

        $this->context->builder->positionAtEnd($reallocBb);
        $realloced = $this->context->builder->call(
            $this->context->lookupFunction('__mm__realloc'),
            $this->context->builder->pointerCast($valuesPtr, $i8p),
            $bytes
        );
        $this->context->builder->branch($afterBb);

        $this->context->builder->positionAtEnd($afterBb);
        $raw = $this->context->builder->phi($i8p);
        $raw->addIncoming($malloced, $mallocBb);
        $raw->addIncoming($realloced, $reallocBb);
        $this->context->builder->store(
            $this->context->builder->pointerCast($raw, $valuePp),
            $this->context->builder->structGep($ht, $map['values'])
        );
        $this->context->builder->store($nc, $this->context->builder->structGep($ht, $map['capacity']));
        $this->context->builder->branch($done);

        $this->context->builder->positionAtEnd($done);
        $this->context->builder->returnVoid();
    }

    private function implementSetLongAt(): void
    {
        $fn = $this->context->lookupFunction('__hashtable__setLongAt');
        $block = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($block);
        $ht = $fn->getParam(0);
        $index = $fn->getParam(1);
        $value = $fn->getParam(2);
        $sizeT = $this->context->getTypeFromString('size_t');
        $one = $sizeT->constInt(1, false);
        $need = $this->context->builder->addNoSignedWrap($index, $one);
        $this->context->builder->call($this->context->lookupFunction('__hashtable__grow'), $ht, $need);
        $map = $this->context->structFieldMap['__hashtable__'];
        $values = $this->context->builder->load($this->context->builder->structGep($ht, $map['values']));
        $entry = $this->context->builder->inBoundsGep($values, $index);
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeLong'),
            $entry,
            $value
        );
        $this->updateIndexMetadata($ht, $map, $index, $need);
        $this->context->builder->returnVoid();
    }

    private function implementSetStringAt(): void
    {
        $fn = $this->context->lookupFunction('__hashtable__setStringAt');
        $block = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($block);
        $ht = $fn->getParam(0);
        $index = $fn->getParam(1);
        $str = $fn->getParam(2);
        $sizeT = $this->context->getTypeFromString('size_t');
        $one = $sizeT->constInt(1, false);
        $need = $this->context->builder->addNoSignedWrap($index, $one);
        $this->context->builder->call($this->context->lookupFunction('__hashtable__grow'), $ht, $need);
        $map = $this->context->structFieldMap['__hashtable__'];
        $values = $this->context->builder->load($this->context->builder->structGep($ht, $map['values']));
        $entry = $this->context->builder->inBoundsGep($values, $index);
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeString'),
            $entry,
            $str
        );
        $this->updateIndexMetadata($ht, $map, $index, $need);
        $this->context->builder->returnVoid();
    }

    private function implementReadLongAt(): void
    {
        $fn = $this->context->lookupFunction('__hashtable__readLongAt');
        $block = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($block);
        $ht = $fn->getParam(0);
        $index = $fn->getParam(1);
        $map = $this->context->structFieldMap['__hashtable__'];
        $nextFree = $this->context->builder->load(
            $this->context->builder->structGep($ht, $map['nextFreeElement'])
        );
        $inRange = $this->context->builder->icmp(Builder::INT_ULT, $index, $nextFree);
        $ok = $fn->appendBasicBlock('read_ok');
        $zeroBlock = $fn->appendBasicBlock('read_zero');
        $merge = $fn->appendBasicBlock('read_merge');
        $this->context->builder->branchIf($inRange, $ok, $zeroBlock);
        $this->context->builder->positionAtEnd($ok);
        $values = $this->context->builder->load($this->context->builder->structGep($ht, $map['values']));
        $entry = $this->context->builder->inBoundsGep($values, $index);
        $val = $this->context->builder->call($this->context->lookupFunction('__value__readLong'), $entry);
        $this->context->builder->branch($merge);
        $this->context->builder->positionAtEnd($zeroBlock);
        $this->context->builder->branch($merge);
        $this->context->builder->positionAtEnd($merge);
        $result = $this->context->builder->phi($this->context->getTypeFromString('int64'));
        $result->addIncoming($val, $ok);
        $result->addIncoming($this->context->getTypeFromString('int64')->constInt(0, false), $zeroBlock);
        $this->context->builder->returnValue($result);
    }

    private function implementGetNumElements(): void
    {
        $fn = $this->context->lookupFunction('__hashtable__getNumElements');
        $block = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($block);
        $ht = $fn->getParam(0);
        $map = $this->context->structFieldMap['__hashtable__'];
        $num = $this->context->builder->load($this->context->builder->structGep($ht, $map['numElements']));
        $this->context->builder->returnValue($num);
    }

    private function implementOffsetIsSet(): void
    {
        $fn = $this->context->lookupFunction('__hashtable__offsetIsSet');
        $block = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($block);
        $ht = $fn->getParam(0);
        $index = $fn->getParam(1);
        $map = $this->context->structFieldMap['__hashtable__'];
        $i1 = $this->context->getTypeFromString('int1');
        $nextFree = $this->context->builder->load(
            $this->context->builder->structGep($ht, $map['nextFreeElement'])
        );
        $inRange = $this->context->builder->icmp(Builder::INT_ULT, $index, $nextFree);
        $ok = $fn->appendBasicBlock('isset_ok');
        $no = $fn->appendBasicBlock('isset_no');
        $merge = $fn->appendBasicBlock('isset_merge');
        $this->context->builder->branchIf($inRange, $ok, $no);
        $this->context->builder->positionAtEnd($no);
        $this->context->builder->branch($merge);
        $this->context->builder->positionAtEnd($ok);
        $values = $this->context->builder->load($this->context->builder->structGep($ht, $map['values']));
        $entry = $this->context->builder->inBoundsGep($values, $index);
        $typeByte = $this->context->builder->load(
            $this->context->builder->structGep($entry, $this->context->structFieldMap['__value__']['type'])
        );
        $nullType = $this->context->getTypeFromString('int8')->constInt(0, false);
        $set = $this->context->builder->icmp(Builder::INT_NE, $typeByte, $nullType);
        $this->context->builder->branch($merge);
        $this->context->builder->positionAtEnd($merge);
        $result = $this->context->builder->phi($i1);
        $result->addIncoming($set, $ok);
        $result->addIncoming($i1->constInt(0, false), $no);
        $this->context->builder->returnValue($result);
    }

    /**
     * @param array<string, int> $map
     */
    private function updateIndexMetadata(PHPLLVM\Value $ht, array $map, PHPLLVM\Value $index, PHPLLVM\Value $need): void
    {
        $nextFree = $this->context->builder->load(
            $this->context->builder->structGep($ht, $map['nextFreeElement'])
        );
        $numElements = $this->context->builder->load(
            $this->context->builder->structGep($ht, $map['numElements'])
        );
        $updateNext = $this->context->builder->icmp(Builder::INT_UGE, $index, $nextFree);
        $newNext = $this->context->builder->select($updateNext, $need, $nextFree);
        $this->context->builder->store(
            $newNext,
            $this->context->builder->structGep($ht, $map['nextFreeElement'])
        );
        $updateNum = $this->context->builder->icmp(Builder::INT_UGE, $index, $numElements);
        $newNum = $this->context->builder->select($updateNum, $need, $numElements);
        $this->context->builder->store(
            $newNum,
            $this->context->builder->structGep($ht, $map['numElements'])
        );
    }
}
