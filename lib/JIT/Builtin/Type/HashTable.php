<?php

declare(strict_types=1);

/**
 * Packed-list __hashtable__ for JIT/AOT (integer indices plus string keys for superglobals).
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
        $nodeStruct = $this->context->context->namedStructType('__strkey_node__');
        $this->context->registerType('__strkey_node__', $nodeStruct);
        $this->context->registerType('__strkey_node__*', $nodeStruct->pointerType(0));
        $nodeStruct->setBody(
            false,
            $this->context->getTypeFromString('__ref__'),
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('__value__'),
            $nodeStruct->pointerType(0),
        );
        $this->context->structFieldMap['__strkey_node__'] = [
            'ref' => 0,
            'key' => 1,
            'value' => 2,
            'next' => 3,
        ];

        $objNodeStruct = $this->context->context->namedStructType('__objkey_node__');
        $this->context->registerType('__objkey_node__', $objNodeStruct);
        $this->context->registerType('__objkey_node__*', $objNodeStruct->pointerType(0));
        $objNodeStruct->setBody(
            false,
            $this->context->getTypeFromString('__ref__'),
            $this->context->getTypeFromString('__object__*'),
            $this->context->getTypeFromString('__value__'),
            $objNodeStruct->pointerType(0),
        );
        $this->context->structFieldMap['__objkey_node__'] = [
            'ref' => 0,
            'key' => 1,
            'value' => 2,
            'next' => 3,
        ];

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
            $this->context->getTypeFromString('__strkey_node__*'),
            $this->context->getTypeFromString('__objkey_node__*'),
        );
        $this->context->structFieldMap['__hashtable__'] = [
            'ref' => 0,
            'numElements' => 1,
            'nextFreeElement' => 2,
            'capacity' => 3,
            'values' => 4,
            'strKeys' => 5,
            'objKeys' => 6,
        ];

        $this->registerFn('__hashtable__alloc', '__hashtable__*', []);
        $this->registerFn('__hashtable__grow', 'void', ['__hashtable__*', 'size_t']);
        $this->registerFn('__hashtable__setLongAt', 'void', ['__hashtable__*', 'size_t', 'int64']);
        $this->registerFn('__hashtable__setDoubleAt', 'void', ['__hashtable__*', 'size_t', 'double']);
        $this->registerFn('__hashtable__setBoolAt', 'void', ['__hashtable__*', 'size_t', 'int1']);
        $this->registerFn('__hashtable__setStringAt', 'void', ['__hashtable__*', 'size_t', '__string__*']);
        $this->registerFn('__hashtable__readLongAt', 'int64', ['__hashtable__*', 'size_t']);
        $this->registerFn('__hashtable__readStringAt', '__string__*', ['__hashtable__*', 'size_t']);
        $this->registerFn('__hashtable__getNumElements', 'size_t', ['__hashtable__*']);
        $this->registerFn('__hashtable__offsetIsSet', 'int1', ['__hashtable__*', 'size_t']);
        $this->registerFn('__hashtable__setStringKeyString', 'void', ['__hashtable__*', '__string__*', '__string__*']);
        $this->registerFn('__hashtable__setStringKeyHashtable', 'void', ['__hashtable__*', '__string__*', '__hashtable__*']);
        $this->registerFn('__hashtable__setStringKeyLong', 'void', ['__hashtable__*', '__string__*', 'int64']);
        $this->registerFn('__hashtable__setStringKeyBool', 'void', ['__hashtable__*', '__string__*', 'int1']);
        $this->registerFn('__hashtable__offsetIsSetStringKey', 'int1', ['__hashtable__*', '__string__*']);
        $this->registerFn('__hashtable__peekStringKeyValue', '__value__*', ['__hashtable__*', '__string__*']);
        $this->registerFn('__hashtable__readStringKeyValue', '__value__*', ['__hashtable__*', '__string__*']);
        $this->registerFn('__hashtable__readStringKeyHashtable', '__hashtable__*', ['__hashtable__*', '__string__*']);
        $this->registerFn('__hashtable__readObjectKeyValue', '__value__*', ['__hashtable__*', '__object__*']);
        $this->registerFn('__hashtable__setObjectKeyLong', 'void', ['__hashtable__*', '__object__*', 'int64']);
        $this->registerFn('__hashtable__setObjectKeyObject', 'void', ['__hashtable__*', '__object__*', '__object__*']);
        $this->registerFn('__hashtable__offsetIsSetObjectKey', 'int1', ['__hashtable__*', '__object__*']);
        $this->registerFn('__value__readHashtable', '__hashtable__*', ['__value__*']);
        $this->registerFn('__value__writeHashtable', 'void', ['__value__*', '__hashtable__*']);
        $this->registerFn('__hashtable__sortPacked', 'void', ['__hashtable__*']);

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
        $this->ensureLibcStringCompare();
        $this->implementAlloc();
        $this->implementGrow();
        $this->implementSetLongAt();
        $this->implementSetStringAt();
        $this->implementReadLongAt();
        $this->implementReadStringAt();
        $this->implementGetNumElements();
        $this->implementOffsetIsSet();
        $this->implementSetStringKeyString();
        $this->implementSetStringKeyLong();
        $this->implementSetStringKeyBool();
        $this->implementSetStringKeyHashtable();
        $this->implementOffsetIsSetStringKey();
        $this->implementPeekStringKeyValue();
        $this->implementReadStringKeyValue();
        $this->implementReadStringKeyHashtable();
        $this->implementReadObjectKeyValue();
        $this->implementSetObjectKeyLong();
        $this->implementSetObjectKeyObject();
        $this->implementOffsetIsSetObjectKey();
        $this->implementValueReadHashtable();
        $this->implementValueWriteHashtable();
        $this->implementSortPacked();
    }

    private function ensureLibcStringCompare(): void
    {
        try {
            $this->context->lookupFunction('strcmp');
        } catch (\Throwable $e) {
            $i8p = $this->context->getTypeFromString('int8*');
            $i32 = $this->context->getTypeFromString('int32');
            $ft = $this->context->context->functionType($i32, false, $i8p, $i8p);
            $fn = $this->context->module->addFunction('strcmp', $ft);
            $this->context->registerFunction('strcmp', $fn);
        }
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
        $nullStrKeys = $this->context->getTypeFromString('__strkey_node__*')->constNull();
        $this->context->builder->store(
            $nullStrKeys,
            $this->context->builder->structGep($ht, $map['strKeys'])
        );
        $nullObjKeys = $this->context->getTypeFromString('__objkey_node__*')->constNull();
        $this->context->builder->store(
            $nullObjKeys,
            $this->context->builder->structGep($ht, $map['objKeys'])
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
        // Append successor blocks: inserting before $entry would steal the function entry block.
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

        $zeroI8 = $this->context->getTypeFromString('int8')->constInt(0, false);

        $this->context->builder->positionAtEnd($mallocBb);
        $malloced = $this->context->builder->call($this->context->lookupFunction('__mm__malloc'), $bytes);
        $this->context->intrinsic->memset($malloced, $zeroI8, $bytes, false);
        $this->context->builder->branch($afterBb);

        $this->context->builder->positionAtEnd($reallocBb);
        $realloced = $this->context->builder->call(
            $this->context->lookupFunction('__mm__realloc'),
            $this->context->builder->pointerCast($valuesPtr, $i8p),
            $bytes
        );
        $oldBytes = $this->context->builder->mulNoSignedWrap($cap, $valueSize);
        $tailBytes = $this->context->builder->subNoSignedWrap($bytes, $oldBytes);
        $tailStart = $this->context->builder->inBoundsGEP($realloced, $oldBytes);
        $this->context->intrinsic->memset($tailStart, $zeroI8, $tailBytes, false);
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

    private function implementSetDoubleAt(): void
    {
        $fn = $this->context->lookupFunction('__hashtable__setDoubleAt');
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
            $this->context->lookupFunction('__value__writeDouble'),
            $entry,
            $value
        );
        $this->updateIndexMetadata($ht, $map, $index, $need);
        $this->context->builder->returnVoid();
    }

    private function implementSetBoolAt(): void
    {
        $fn = $this->context->lookupFunction('__hashtable__setBoolAt');
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
        $i8 = $this->context->getTypeFromString('int8');
        $this->context->builder->store(
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false),
            $this->context->builder->structGep($entry, $this->context->structFieldMap['__value__']['type'])
        );
        $boolByte = $this->context->builder->zExt($value, $i8);
        $valueField = $this->context->builder->structGep(
            $entry,
            $this->context->structFieldMap['__value__']['value']
        );
        $i32 = $this->context->getTypeFromString('int32');
        $i64 = $this->context->getTypeFromString('int64');
        $firstByte = $this->context->builder->inBoundsGEP(
            $valueField,
            $i32->constInt(0, false),
            $i64->constInt(0, false)
        );
        $this->context->builder->store($boolByte, $firstByte);
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

    private function implementReadStringAt(): void
    {
        $fn = $this->context->lookupFunction('__hashtable__readStringAt');
        $block = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($block);
        $ht = $fn->getParam(0);
        $index = $fn->getParam(1);
        $map = $this->context->structFieldMap['__hashtable__'];
        $stringPtr = $this->context->getTypeFromString('__string__*');
        $nextFree = $this->context->builder->load(
            $this->context->builder->structGep($ht, $map['nextFreeElement'])
        );
        $inRange = $this->context->builder->icmp(Builder::INT_ULT, $index, $nextFree);
        $ok = $fn->appendBasicBlock('read_str_ok');
        $emptyBlock = $fn->appendBasicBlock('read_str_empty');
        $merge = $fn->appendBasicBlock('read_str_merge');
        $this->context->builder->branchIf($inRange, $ok, $emptyBlock);
        $this->context->builder->positionAtEnd($ok);
        $values = $this->context->builder->load($this->context->builder->structGep($ht, $map['values']));
        $entry = $this->context->builder->inBoundsGep($values, $index);
        $str = $this->context->builder->call($this->context->lookupFunction('__value__readString'), $entry);
        $str = $this->context->builder->call($this->context->lookupFunction('__string__separate'), $str);
        $this->context->builder->branch($merge);
        $this->context->builder->positionAtEnd($emptyBlock);
        $empty = $this->context->builder->call(
            $this->context->lookupFunction('__string__alloc'),
            $this->context->getTypeFromString('int64')->constInt(0, false)
        );
        $this->context->builder->branch($merge);
        $this->context->builder->positionAtEnd($merge);
        $result = $this->context->builder->phi($stringPtr);
        $result->addIncoming($str, $ok);
        $result->addIncoming($empty, $emptyBlock);
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

    private function implementSetStringKeyString(): void
    {
        $fn = $this->context->lookupFunction('__hashtable__setStringKeyString');
        $block = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($block);
        $ht = $fn->getParam(0);
        $key = $fn->getParam(1);
        $str = $fn->getParam(2);

        $htMap = $this->context->structFieldMap['__hashtable__'];
        $nodeMap = $this->context->structFieldMap['__strkey_node__'];
        $headSlot = $this->context->builder->structGep($ht, $htMap['strKeys']);
        $head = $this->context->builder->load($headSlot);

        $done = $fn->appendBasicBlock('strkey_set_done');
        $prepend = $fn->appendBasicBlock('strkey_set_prepend');
        $emptyHead = $fn->appendBasicBlock('strkey_set_empty_head');
        $loopHead = $fn->appendBasicBlock('strkey_set_head');
        $loopBody = $fn->appendBasicBlock('strkey_set_body');
        $this->context->builder->branch($loopHead);

        $this->context->builder->positionAtEnd($loopHead);
        $node = $this->context->builder->phi($head->typeOf());
        $node->addIncoming($head, $block);
        $isNull = $this->context->builder->icmp(Builder::INT_EQ, $node, $node->typeOf()->constNull());
        $this->context->builder->branchIf($isNull, $prepend, $loopBody);

        $this->context->builder->positionAtEnd($loopBody);
        $nodeKey = $this->context->builder->load($this->context->builder->structGep($node, $nodeMap['key']));
        $cmp = $this->context->builder->call(
            $this->context->lookupFunction('strcmp'),
            $this->stringDataPtr($key),
            $this->stringDataPtr($nodeKey)
        );
        $isMatch = $this->context->builder->icmp(Builder::INT_EQ, $cmp, $cmp->typeOf()->constInt(0, false));
        $update = $fn->appendBasicBlock('strkey_set_update');
        $next = $fn->appendBasicBlock('strkey_set_next');
        $this->context->builder->branchIf($isMatch, $update, $next);

        $this->context->builder->positionAtEnd($update);
        $valField = $this->context->builder->structGep($node, $nodeMap['value']);
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeString'),
            $valField,
            $str
        );
        $this->context->builder->branch($done);

        $this->context->builder->positionAtEnd($next);
        $nextNode = $this->context->builder->load($this->context->builder->structGep($node, $nodeMap['next']));
        $this->context->builder->branch($loopHead);
        $node->addIncoming($nextNode, $next);

        $this->context->builder->positionAtEnd($prepend);
        $nodeType = $this->context->getTypeFromString('__strkey_node__');
        $newNode = $this->context->memory->malloc($nodeType);
        $typeinfo = $this->context->getTypeFromString('int32')->constInt(
            Refcount::TYPE_INFO_TYPE_STRING | Refcount::TYPE_INFO_REFCOUNTED,
            false
        );
        $ref = $this->context->builder->pointerCast(
            $newNode,
            $this->context->getTypeFromString('__ref__virtual*')
        );
        $this->context->builder->call($this->context->lookupFunction('__ref__init'), $typeinfo, $ref);
        $storedKey = $this->context->builder->call($this->context->lookupFunction('__string__separate'), $key);
        $this->context->builder->store($storedKey, $this->context->builder->structGep($newNode, $nodeMap['key']));
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeString'),
            $this->context->builder->structGep($newNode, $nodeMap['value']),
            $str
        );
        $this->context->builder->store(
            $newNode->typeOf()->constNull(),
            $this->context->builder->structGep($newNode, $nodeMap['next'])
        );
        $tail = $fn->appendBasicBlock('strkey_set_tail');
        $tailWalk = $fn->appendBasicBlock('strkey_set_tail_walk');
        $tailDone = $fn->appendBasicBlock('strkey_set_tail_done');
        $this->context->builder->branch($tail);

        $this->context->builder->positionAtEnd($tail);
        $isEmpty = $this->context->builder->icmp(Builder::INT_EQ, $head, $head->typeOf()->constNull());
        $this->context->builder->branchIf($isEmpty, $emptyHead, $tailWalk);

        $this->context->builder->positionAtEnd($tailWalk);
        $walkNode = $this->context->builder->phi($head->typeOf());
        $walkNode->addIncoming($head, $tail);
        $nextWalk = $this->context->builder->load($this->context->builder->structGep($walkNode, $nodeMap['next']));
        $atEnd = $this->context->builder->icmp(Builder::INT_EQ, $nextWalk, $nextWalk->typeOf()->constNull());
        $this->context->builder->branchIf($atEnd, $tailDone, $tailWalk);
        $walkNode->addIncoming($nextWalk, $tailWalk);

        $this->context->builder->positionAtEnd($tailDone);
        $this->context->builder->store(
            $newNode,
            $this->context->builder->structGep($walkNode, $nodeMap['next'])
        );
        $this->incrementNumElements($ht);
        $this->context->builder->branch($done);

        $this->context->builder->positionAtEnd($emptyHead);
        $this->context->builder->store($newNode, $headSlot);
        $this->incrementNumElements($ht);
        $this->context->builder->branch($done);

        $this->context->builder->positionAtEnd($done);
        $this->context->builder->returnVoid();
    }

    private function implementSetStringKeyHashtable(): void
    {
        $fn = $this->context->lookupFunction('__hashtable__setStringKeyHashtable');
        $block = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($block);
        $ht = $fn->getParam(0);
        $key = $fn->getParam(1);
        $child = $fn->getParam(2);

        $htMap = $this->context->structFieldMap['__hashtable__'];
        $nodeMap = $this->context->structFieldMap['__strkey_node__'];
        $headSlot = $this->context->builder->structGep($ht, $htMap['strKeys']);
        $head = $this->context->builder->load($headSlot);

        $done = $fn->appendBasicBlock('strkey_ht_done');
        $prepend = $fn->appendBasicBlock('strkey_ht_prepend');
        $loopHead = $fn->appendBasicBlock('strkey_ht_head');
        $loopBody = $fn->appendBasicBlock('strkey_ht_body');
        $this->context->builder->branch($loopHead);

        $this->context->builder->positionAtEnd($loopHead);
        $node = $this->context->builder->phi($head->typeOf());
        $node->addIncoming($head, $block);
        $isNull = $this->context->builder->icmp(Builder::INT_EQ, $node, $node->typeOf()->constNull());
        $this->context->builder->branchIf($isNull, $prepend, $loopBody);

        $this->context->builder->positionAtEnd($loopBody);
        $nodeKey = $this->context->builder->load($this->context->builder->structGep($node, $nodeMap['key']));
        $cmp = $this->context->builder->call(
            $this->context->lookupFunction('strcmp'),
            $this->stringDataPtr($key),
            $this->stringDataPtr($nodeKey)
        );
        $isMatch = $this->context->builder->icmp(Builder::INT_EQ, $cmp, $cmp->typeOf()->constInt(0, false));
        $update = $fn->appendBasicBlock('strkey_ht_update');
        $next = $fn->appendBasicBlock('strkey_ht_next');
        $this->context->builder->branchIf($isMatch, $update, $next);

        $this->context->builder->positionAtEnd($update);
        $valField = $this->context->builder->structGep($node, $nodeMap['value']);
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeHashtable'),
            $valField,
            $child
        );
        $this->context->builder->branch($done);

        $this->context->builder->positionAtEnd($next);
        $nextNode = $this->context->builder->load($this->context->builder->structGep($node, $nodeMap['next']));
        $this->context->builder->branch($loopHead);
        $node->addIncoming($nextNode, $next);

        $this->context->builder->positionAtEnd($prepend);
        $nodeType = $this->context->getTypeFromString('__strkey_node__');
        $newNode = $this->context->memory->malloc($nodeType);
        $typeinfo = $this->context->getTypeFromString('int32')->constInt(
            Refcount::TYPE_INFO_TYPE_STRING | Refcount::TYPE_INFO_REFCOUNTED,
            false
        );
        $ref = $this->context->builder->pointerCast(
            $newNode,
            $this->context->getTypeFromString('__ref__virtual*')
        );
        $this->context->builder->call($this->context->lookupFunction('__ref__init'), $typeinfo, $ref);
        $storedKey = $this->context->builder->call($this->context->lookupFunction('__string__separate'), $key);
        $this->context->builder->store($storedKey, $this->context->builder->structGep($newNode, $nodeMap['key']));
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeHashtable'),
            $this->context->builder->structGep($newNode, $nodeMap['value']),
            $child
        );
        $this->context->builder->store($head, $this->context->builder->structGep($newNode, $nodeMap['next']));
        $this->context->builder->store($newNode, $headSlot);
        $this->context->builder->branch($done);

        $this->context->builder->positionAtEnd($done);
        $this->context->builder->returnVoid();
    }

    private function implementSetStringKeyLong(): void
    {
        $fn = $this->context->lookupFunction('__hashtable__setStringKeyLong');
        $block = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($block);
        $ht = $fn->getParam(0);
        $key = $fn->getParam(1);
        $long = $fn->getParam(2);

        $htMap = $this->context->structFieldMap['__hashtable__'];
        $nodeMap = $this->context->structFieldMap['__strkey_node__'];
        $headSlot = $this->context->builder->structGep($ht, $htMap['strKeys']);
        $head = $this->context->builder->load($headSlot);

        $done = $fn->appendBasicBlock('strkey_long_done');
        $prepend = $fn->appendBasicBlock('strkey_long_prepend');
        $loopHead = $fn->appendBasicBlock('strkey_long_head');
        $loopBody = $fn->appendBasicBlock('strkey_long_body');
        $this->context->builder->branch($loopHead);

        $this->context->builder->positionAtEnd($loopHead);
        $node = $this->context->builder->phi($head->typeOf());
        $node->addIncoming($head, $block);
        $isNull = $this->context->builder->icmp(Builder::INT_EQ, $node, $node->typeOf()->constNull());
        $this->context->builder->branchIf($isNull, $prepend, $loopBody);

        $this->context->builder->positionAtEnd($loopBody);
        $nodeKey = $this->context->builder->load($this->context->builder->structGep($node, $nodeMap['key']));
        $cmp = $this->context->builder->call(
            $this->context->lookupFunction('strcmp'),
            $this->stringDataPtr($key),
            $this->stringDataPtr($nodeKey)
        );
        $isMatch = $this->context->builder->icmp(Builder::INT_EQ, $cmp, $cmp->typeOf()->constInt(0, false));
        $update = $fn->appendBasicBlock('strkey_long_update');
        $next = $fn->appendBasicBlock('strkey_long_next');
        $this->context->builder->branchIf($isMatch, $update, $next);

        $this->context->builder->positionAtEnd($update);
        $valField = $this->context->builder->structGep($node, $nodeMap['value']);
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeLong'),
            $valField,
            $long
        );
        $this->context->builder->branch($done);

        $this->context->builder->positionAtEnd($next);
        $nextNode = $this->context->builder->load($this->context->builder->structGep($node, $nodeMap['next']));
        $this->context->builder->branch($loopHead);
        $node->addIncoming($nextNode, $next);

        $this->context->builder->positionAtEnd($prepend);
        $nodeType = $this->context->getTypeFromString('__strkey_node__');
        $newNode = $this->context->memory->malloc($nodeType);
        $typeinfo = $this->context->getTypeFromString('int32')->constInt(
            Refcount::TYPE_INFO_TYPE_STRING | Refcount::TYPE_INFO_REFCOUNTED,
            false
        );
        $ref = $this->context->builder->pointerCast(
            $newNode,
            $this->context->getTypeFromString('__ref__virtual*')
        );
        $this->context->builder->call($this->context->lookupFunction('__ref__init'), $typeinfo, $ref);
        $storedKey = $this->context->builder->call($this->context->lookupFunction('__string__separate'), $key);
        $this->context->builder->store($storedKey, $this->context->builder->structGep($newNode, $nodeMap['key']));
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeLong'),
            $this->context->builder->structGep($newNode, $nodeMap['value']),
            $long
        );
        $this->context->builder->store($head, $this->context->builder->structGep($newNode, $nodeMap['next']));
        $this->context->builder->store($newNode, $headSlot);
        $this->incrementNumElements($ht);
        $this->context->builder->branch($done);

        $this->context->builder->positionAtEnd($done);
        $this->context->builder->returnVoid();
    }

    private function implementSetStringKeyBool(): void
    {
        $fn = $this->context->lookupFunction('__hashtable__setStringKeyBool');
        $block = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($block);
        $ht = $fn->getParam(0);
        $key = $fn->getParam(1);
        $bool = $fn->getParam(2);

        $htMap = $this->context->structFieldMap['__hashtable__'];
        $nodeMap = $this->context->structFieldMap['__strkey_node__'];
        $valMap = $this->context->structFieldMap['__value__'];
        $headSlot = $this->context->builder->structGep($ht, $htMap['strKeys']);
        $head = $this->context->builder->load($headSlot);

        $done = $fn->appendBasicBlock('strkey_bool_done');
        $prepend = $fn->appendBasicBlock('strkey_bool_prepend');
        $loopHead = $fn->appendBasicBlock('strkey_bool_head');
        $loopBody = $fn->appendBasicBlock('strkey_bool_body');
        $this->context->builder->branch($loopHead);

        $this->context->builder->positionAtEnd($loopHead);
        $node = $this->context->builder->phi($head->typeOf());
        $node->addIncoming($head, $block);
        $isNull = $this->context->builder->icmp(Builder::INT_EQ, $node, $node->typeOf()->constNull());
        $this->context->builder->branchIf($isNull, $prepend, $loopBody);

        $this->context->builder->positionAtEnd($loopBody);
        $nodeKey = $this->context->builder->load($this->context->builder->structGep($node, $nodeMap['key']));
        $cmp = $this->context->builder->call(
            $this->context->lookupFunction('strcmp'),
            $this->stringDataPtr($key),
            $this->stringDataPtr($nodeKey)
        );
        $isMatch = $this->context->builder->icmp(Builder::INT_EQ, $cmp, $cmp->typeOf()->constInt(0, false));
        $update = $fn->appendBasicBlock('strkey_bool_update');
        $next = $fn->appendBasicBlock('strkey_bool_next');
        $this->context->builder->branchIf($isMatch, $update, $next);

        $this->context->builder->positionAtEnd($update);
        $valField = $this->context->builder->structGep($node, $nodeMap['value']);
        $this->writeBoolToValueField($valField, $bool);
        $this->context->builder->branch($done);

        $this->context->builder->positionAtEnd($next);
        $nextNode = $this->context->builder->load($this->context->builder->structGep($node, $nodeMap['next']));
        $this->context->builder->branch($loopHead);
        $node->addIncoming($nextNode, $next);

        $this->context->builder->positionAtEnd($prepend);
        $nodeType = $this->context->getTypeFromString('__strkey_node__');
        $newNode = $this->context->memory->malloc($nodeType);
        $typeinfo = $this->context->getTypeFromString('int32')->constInt(
            Refcount::TYPE_INFO_TYPE_STRING | Refcount::TYPE_INFO_REFCOUNTED,
            false
        );
        $ref = $this->context->builder->pointerCast(
            $newNode,
            $this->context->getTypeFromString('__ref__virtual*')
        );
        $this->context->builder->call($this->context->lookupFunction('__ref__init'), $typeinfo, $ref);
        $storedKey = $this->context->builder->call($this->context->lookupFunction('__string__separate'), $key);
        $this->context->builder->store($storedKey, $this->context->builder->structGep($newNode, $nodeMap['key']));
        $this->writeBoolToValueField(
            $this->context->builder->structGep($newNode, $nodeMap['value']),
            $bool
        );
        $this->context->builder->store($head, $this->context->builder->structGep($newNode, $nodeMap['next']));
        $this->context->builder->store($newNode, $headSlot);
        $this->context->builder->branch($done);

        $this->context->builder->positionAtEnd($done);
        $this->context->builder->returnVoid();
    }

    private function writeBoolToValueField(\PHPLLVM\Value $valField, \PHPLLVM\Value $bool): void
    {
        $valMap = $this->context->structFieldMap['__value__'];
        $i8 = $this->context->getTypeFromString('int8');
        $i32 = $this->context->getTypeFromString('int32');
        $i64 = $this->context->getTypeFromString('int64');
        $this->context->builder->store(
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false),
            $this->context->builder->structGep($valField, $valMap['type'])
        );
        $boolByte = $this->context->builder->zExt($bool, $i8);
        $valueField = $this->context->builder->structGep($valField, $valMap['value']);
        $firstByte = $this->context->builder->inBoundsGEP(
            $valueField,
            $i32->constInt(0, false),
            $i64->constInt(0, false)
        );
        $this->context->builder->store($boolByte, $firstByte);
    }

    private function implementOffsetIsSetStringKey(): void
    {
        $fn = $this->context->lookupFunction('__hashtable__offsetIsSetStringKey');
        $block = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($block);
        $ht = $fn->getParam(0);
        $key = $fn->getParam(1);
        $valPtr = $this->lookupStringKeyValue($fn, $block, $ht, $key);
        // lookupStringKeyValue finishes in an internal "done" block; continue in a new block.
        $afterLookup = $fn->appendBasicBlock('strkey_isset_after_lookup');
        $this->context->builder->branch($afterLookup);
        $this->context->builder->positionAtEnd($afterLookup);
        $i1 = $this->context->getTypeFromString('int1');
        $isNull = $this->context->builder->icmp(Builder::INT_EQ, $valPtr, $valPtr->typeOf()->constNull());
        $nullType = $this->context->getTypeFromString('int8')->constInt(0, false);
        $check = $fn->appendBasicBlock('strkey_isset_check');
        $notFound = $fn->appendBasicBlock('strkey_isset_not_found');
        $this->context->builder->branchIf($isNull, $notFound, $check);
        $this->context->builder->positionAtEnd($check);
        $typeByte = $this->context->builder->load(
            $this->context->builder->structGep($valPtr, $this->context->structFieldMap['__value__']['type'])
        );
        $hasValue = $this->context->builder->icmp(Builder::INT_NE, $typeByte, $nullType);
        $this->context->builder->returnValue($hasValue);
        $this->context->builder->positionAtEnd($notFound);
        $result = $i1->constInt(0, false);
        $this->context->builder->returnValue($result);
    }

    /**
     * Hashtable lookup without #273 undefined-key warning (isset / ?? on superglobals).
     */
    private function implementPeekStringKeyValue(): void
    {
        $fn = $this->context->lookupFunction('__hashtable__peekStringKeyValue');
        $block = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($block);
        $ht = $fn->getParam(0);
        $key = $fn->getParam(1);
        $valPtr = $this->lookupStringKeyValue($fn, $block, $ht, $key);
        $afterLookup = $fn->appendBasicBlock('strkey_peek_after_lookup');
        $this->context->builder->branch($afterLookup);
        $this->context->builder->positionAtEnd($afterLookup);
        $this->context->builder->returnValue($valPtr);
    }

    private function implementReadStringKeyValue(): void
    {
        $fn = $this->context->lookupFunction('__hashtable__readStringKeyValue');
        $block = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($block);
        $ht = $fn->getParam(0);
        $key = $fn->getParam(1);
        $valPtr = $this->lookupStringKeyValue($fn, $block, $ht, $key);
        $afterLookup = $fn->appendBasicBlock('strkey_read_val_after_lookup');
        $this->context->builder->branch($afterLookup);
        $this->context->builder->positionAtEnd($afterLookup);
        $isNull = $this->context->builder->icmp(Builder::INT_EQ, $valPtr, $valPtr->typeOf()->constNull());
        $hasValue = $fn->appendBasicBlock('strkey_read_val_has_value');
        $warn = $fn->appendBasicBlock('strkey_read_val_warn');
        $merge = $fn->appendBasicBlock('strkey_read_val_merge');
        $this->context->builder->branchIf($isNull, $warn, $hasValue);
        $this->context->builder->positionAtEnd($warn);
        $strMap = $this->context->structFieldMap['__string__'];
        $i8p = $this->context->getTypeFromString('int8*');
        $keyLen = $this->context->builder->load(
            $this->context->builder->structGep($key, $strMap['length'])
        );
        $keyBytes = $this->stringDataPtr($key);
        $keyCStr = $this->context->builder->pointerCast($keyBytes, $i8p);
        $this->context->builder->call(
            $this->context->lookupFunction('__compiler_undefined_array_key_warning_cstr'),
            $keyCStr,
            $keyLen
        );
        $this->context->builder->branch($merge);
        $this->context->builder->positionAtEnd($hasValue);
        $this->context->builder->branch($merge);
        $this->context->builder->positionAtEnd($merge);
        $this->context->builder->returnValue($valPtr);
    }

    private function implementReadStringKeyHashtable(): void
    {
        $fn = $this->context->lookupFunction('__hashtable__readStringKeyHashtable');
        $block = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($block);
        $ht = $fn->getParam(0);
        $key = $fn->getParam(1);
        $htPtr = $this->context->getTypeFromString('__hashtable__*');
        $valPtr = $this->lookupStringKeyValue($fn, $block, $ht, $key);
        $afterLookup = $fn->appendBasicBlock('strkey_read_ht_after_lookup');
        $this->context->builder->branch($afterLookup);
        $this->context->builder->positionAtEnd($afterLookup);
        $isNull = $this->context->builder->icmp(Builder::INT_EQ, $valPtr, $valPtr->typeOf()->constNull());
        $empty = $fn->appendBasicBlock('strkey_read_ht_empty');
        $read = $fn->appendBasicBlock('strkey_read_ht_read');
        $merge = $fn->appendBasicBlock('strkey_read_ht_merge');
        $this->context->builder->branchIf($isNull, $empty, $read);
        $this->context->builder->positionAtEnd($read);
        $child = $this->context->builder->call(
            $this->context->lookupFunction('__value__readHashtable'),
            $valPtr
        );
        $this->context->builder->branch($merge);
        $this->context->builder->positionAtEnd($empty);
        $this->context->builder->branch($merge);
        $this->context->builder->positionAtEnd($merge);
        $result = $this->context->builder->phi($htPtr);
        $result->addIncoming($child, $read);
        $result->addIncoming($htPtr->constNull(), $empty);
        $this->context->builder->returnValue($result);
    }

    private function implementReadObjectKeyValue(): void
    {
        $fn = $this->context->lookupFunction('__hashtable__readObjectKeyValue');
        $block = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($block);
        $ht = $fn->getParam(0);
        $key = $fn->getParam(1);
        $valPtr = $this->lookupObjectKeyValue($fn, $block, $ht, $key);
        $afterLookup = $fn->appendBasicBlock('objkey_read_val_after_lookup');
        $this->context->builder->branch($afterLookup);
        $this->context->builder->positionAtEnd($afterLookup);
        $this->context->builder->returnValue($valPtr);
    }

    private function implementSetObjectKeyLong(): void
    {
        $fn = $this->context->lookupFunction('__hashtable__setObjectKeyLong');
        $block = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($block);
        $ht = $fn->getParam(0);
        $key = $fn->getParam(1);
        $long = $fn->getParam(2);

        $htMap = $this->context->structFieldMap['__hashtable__'];
        $nodeMap = $this->context->structFieldMap['__objkey_node__'];
        $headSlot = $this->context->builder->structGep($ht, $htMap['objKeys']);
        $head = $this->context->builder->load($headSlot);

        $done = $fn->appendBasicBlock('objkey_long_done');
        $prepend = $fn->appendBasicBlock('objkey_long_prepend');
        $loopHead = $fn->appendBasicBlock('objkey_long_head');
        $loopBody = $fn->appendBasicBlock('objkey_long_body');
        $this->context->builder->branch($loopHead);

        $this->context->builder->positionAtEnd($loopHead);
        $node = $this->context->builder->phi($head->typeOf());
        $node->addIncoming($head, $block);
        $isNull = $this->context->builder->icmp(Builder::INT_EQ, $node, $node->typeOf()->constNull());
        $this->context->builder->branchIf($isNull, $prepend, $loopBody);

        $this->context->builder->positionAtEnd($loopBody);
        $nodeKey = $this->context->builder->load($this->context->builder->structGep($node, $nodeMap['key']));
        $isMatch = $this->context->builder->icmp(Builder::INT_EQ, $nodeKey, $key);
        $update = $fn->appendBasicBlock('objkey_long_update');
        $next = $fn->appendBasicBlock('objkey_long_next');
        $this->context->builder->branchIf($isMatch, $update, $next);

        $this->context->builder->positionAtEnd($update);
        $valField = $this->context->builder->structGep($node, $nodeMap['value']);
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeLong'),
            $valField,
            $long
        );
        $this->context->builder->branch($done);

        $this->context->builder->positionAtEnd($next);
        $nextNode = $this->context->builder->load($this->context->builder->structGep($node, $nodeMap['next']));
        $this->context->builder->branch($loopHead);
        $node->addIncoming($nextNode, $next);

        $this->context->builder->positionAtEnd($prepend);
        $nodeType = $this->context->getTypeFromString('__objkey_node__');
        $newNode = $this->context->memory->malloc($nodeType);
        $typeinfo = $this->context->getTypeFromString('int32')->constInt(
            Refcount::TYPE_INFO_TYPE_OBJECT | Refcount::TYPE_INFO_REFCOUNTED,
            false
        );
        $ref = $this->context->builder->pointerCast(
            $newNode,
            $this->context->getTypeFromString('__ref__virtual*')
        );
        $this->context->builder->call($this->context->lookupFunction('__ref__init'), $typeinfo, $ref);
        $this->context->builder->store($key, $this->context->builder->structGep($newNode, $nodeMap['key']));
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeLong'),
            $this->context->builder->structGep($newNode, $nodeMap['value']),
            $long
        );
        $this->context->builder->store($head, $this->context->builder->structGep($newNode, $nodeMap['next']));
        $this->context->builder->store($newNode, $headSlot);
        $this->incrementNumElements($ht);
        $this->context->builder->branch($done);

        $this->context->builder->positionAtEnd($done);
        $this->context->builder->returnVoid();
    }

    private function implementSetObjectKeyObject(): void
    {
        $fn = $this->context->lookupFunction('__hashtable__setObjectKeyObject');
        $block = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($block);
        $ht = $fn->getParam(0);
        $key = $fn->getParam(1);
        $object = $fn->getParam(2);

        $htMap = $this->context->structFieldMap['__hashtable__'];
        $nodeMap = $this->context->structFieldMap['__objkey_node__'];
        $headSlot = $this->context->builder->structGep($ht, $htMap['objKeys']);
        $head = $this->context->builder->load($headSlot);

        $done = $fn->appendBasicBlock('objkey_obj_done');
        $prepend = $fn->appendBasicBlock('objkey_obj_prepend');
        $loopHead = $fn->appendBasicBlock('objkey_obj_head');
        $loopBody = $fn->appendBasicBlock('objkey_obj_body');
        $this->context->builder->branch($loopHead);

        $this->context->builder->positionAtEnd($loopHead);
        $node = $this->context->builder->phi($head->typeOf());
        $node->addIncoming($head, $block);
        $isNull = $this->context->builder->icmp(Builder::INT_EQ, $node, $node->typeOf()->constNull());
        $this->context->builder->branchIf($isNull, $prepend, $loopBody);

        $this->context->builder->positionAtEnd($loopBody);
        $nodeKey = $this->context->builder->load($this->context->builder->structGep($node, $nodeMap['key']));
        $isMatch = $this->context->builder->icmp(Builder::INT_EQ, $nodeKey, $key);
        $update = $fn->appendBasicBlock('objkey_obj_update');
        $next = $fn->appendBasicBlock('objkey_obj_next');
        $this->context->builder->branchIf($isMatch, $update, $next);

        $this->context->builder->positionAtEnd($update);
        $valField = $this->context->builder->structGep($node, $nodeMap['value']);
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeObject'),
            $valField,
            $object
        );
        $this->context->builder->branch($done);

        $this->context->builder->positionAtEnd($next);
        $nextNode = $this->context->builder->load($this->context->builder->structGep($node, $nodeMap['next']));
        $this->context->builder->branch($loopHead);
        $node->addIncoming($nextNode, $next);

        $this->context->builder->positionAtEnd($prepend);
        $nodeType = $this->context->getTypeFromString('__objkey_node__');
        $newNode = $this->context->memory->malloc($nodeType);
        $typeinfo = $this->context->getTypeFromString('int32')->constInt(
            Refcount::TYPE_INFO_TYPE_OBJECT | Refcount::TYPE_INFO_REFCOUNTED,
            false
        );
        $ref = $this->context->builder->pointerCast(
            $newNode,
            $this->context->getTypeFromString('__ref__virtual*')
        );
        $this->context->builder->call($this->context->lookupFunction('__ref__init'), $typeinfo, $ref);
        $this->context->builder->store($key, $this->context->builder->structGep($newNode, $nodeMap['key']));
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeObject'),
            $this->context->builder->structGep($newNode, $nodeMap['value']),
            $object
        );
        $this->context->builder->store($head, $this->context->builder->structGep($newNode, $nodeMap['next']));
        $this->context->builder->store($newNode, $headSlot);
        $this->incrementNumElements($ht);
        $this->context->builder->branch($done);

        $this->context->builder->positionAtEnd($done);
        $this->context->builder->returnVoid();
    }

    private function implementOffsetIsSetObjectKey(): void
    {
        $fn = $this->context->lookupFunction('__hashtable__offsetIsSetObjectKey');
        $block = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($block);
        $ht = $fn->getParam(0);
        $key = $fn->getParam(1);
        $valPtr = $this->lookupObjectKeyValue($fn, $block, $ht, $key);
        $afterLookup = $fn->appendBasicBlock('objkey_isset_after_lookup');
        $this->context->builder->branch($afterLookup);
        $this->context->builder->positionAtEnd($afterLookup);
        $i1 = $this->context->getTypeFromString('int1');
        $isNull = $this->context->builder->icmp(Builder::INT_EQ, $valPtr, $valPtr->typeOf()->constNull());
        $nullType = $this->context->getTypeFromString('int8')->constInt(0, false);
        $check = $fn->appendBasicBlock('objkey_isset_check');
        $notFound = $fn->appendBasicBlock('objkey_isset_not_found');
        $this->context->builder->branchIf($isNull, $notFound, $check);
        $this->context->builder->positionAtEnd($check);
        $typeByte = $this->context->builder->load(
            $this->context->builder->structGep($valPtr, $this->context->structFieldMap['__value__']['type'])
        );
        $hasValue = $this->context->builder->icmp(Builder::INT_NE, $typeByte, $nullType);
        $this->context->builder->returnValue($hasValue);
        $this->context->builder->positionAtEnd($notFound);
        $this->context->builder->returnValue($i1->constInt(0, false));
    }

    private function lookupObjectKeyValue(
        PHPLLVM\Value\Function_ $fn,
        PHPLLVM\BasicBlock $block,
        PHPLLVM\Value $ht,
        PHPLLVM\Value $key
    ): PHPLLVM\Value {
        $htMap = $this->context->structFieldMap['__hashtable__'];
        $nodeMap = $this->context->structFieldMap['__objkey_node__'];
        $head = $this->context->builder->load($this->context->builder->structGep($ht, $htMap['objKeys']));
        $valuePtrType = $this->context->getTypeFromString('__value__*');
        $nodePtrType = $head->typeOf();

        $currentSlot = $this->context->builder->alloca($nodePtrType, 1, 'objkey_current');
        $this->context->builder->store($head, $currentSlot);

        $resultSlot = $this->context->builder->alloca($valuePtrType, 1, 'objkey_lookup_result');
        $this->context->builder->store($valuePtrType->constNull(), $resultSlot);

        $notFound = $fn->appendBasicBlock('objkey_lookup_not_found');
        $loopHead = $fn->appendBasicBlock('objkey_lookup_head');
        $loopBody = $fn->appendBasicBlock('objkey_lookup_body');
        $found = $fn->appendBasicBlock('objkey_lookup_found');
        $done = $fn->appendBasicBlock('objkey_lookup_done');
        $this->context->builder->branch($loopHead);

        $this->context->builder->positionAtEnd($loopHead);
        $node = $this->context->builder->load($currentSlot);
        $isNull = $this->context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $this->context->builder->branchIf($isNull, $notFound, $loopBody);

        $this->context->builder->positionAtEnd($loopBody);
        $nodeKey = $this->context->builder->load($this->context->builder->structGep($node, $nodeMap['key']));
        $isMatch = $this->context->builder->icmp(Builder::INT_EQ, $nodeKey, $key);
        $next = $fn->appendBasicBlock('objkey_lookup_next');
        $this->context->builder->branchIf($isMatch, $found, $next);

        $this->context->builder->positionAtEnd($found);
        $valField = $this->context->builder->structGep($node, $nodeMap['value']);
        $this->context->builder->store($valField, $resultSlot);
        $this->context->builder->branch($done);

        $this->context->builder->positionAtEnd($next);
        $nextNode = $this->context->builder->load($this->context->builder->structGep($node, $nodeMap['next']));
        $this->context->builder->store($nextNode, $currentSlot);
        $this->context->builder->branch($loopHead);

        $this->context->builder->positionAtEnd($notFound);
        $this->context->builder->branch($done);

        $this->context->builder->positionAtEnd($done);

        return $this->context->builder->load($resultSlot);
    }

    private function implementValueReadHashtable(): void
    {
        $fn = $this->context->lookupFunction('__value__readHashtable');
        $block = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($block);
        $value = $fn->getParam(0);
        $map = $this->context->structFieldMap['__value__'];
        $htPtr = $this->context->getTypeFromString('__hashtable__*');
        $typeByte = $this->context->builder->load($this->context->builder->structGep($value, $map['type']));
        $expected = $this->context->getTypeFromString('int8')->constInt(Variable::TYPE_HASHTABLE, false);
        $isHt = $this->context->builder->icmp(Builder::INT_EQ, $typeByte, $expected);
        $ok = $fn->appendBasicBlock('read_ht_ok');
        $empty = $fn->appendBasicBlock('read_ht_empty');
        $merge = $fn->appendBasicBlock('read_ht_merge');
        $this->context->builder->branchIf($isHt, $ok, $empty);
        $this->context->builder->positionAtEnd($ok);
        $ptrField = $this->context->builder->structGep($value, $map['value']);
        $htSlot = $this->context->builder->pointerCast($ptrField, $htPtr->pointerType(0));
        $stored = $this->context->builder->load($htSlot);
        $this->context->builder->branch($merge);
        $this->context->builder->positionAtEnd($empty);
        $this->context->builder->branch($merge);
        $this->context->builder->positionAtEnd($merge);
        $result = $this->context->builder->phi($htPtr);
        $result->addIncoming($stored, $ok);
        $result->addIncoming($htPtr->constNull(), $empty);
        $this->context->builder->returnValue($result);
    }

    private function implementValueWriteHashtable(): void
    {
        $fn = $this->context->lookupFunction('__value__writeHashtable');
        $block = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($block);
        $value = $fn->getParam(0);
        $hashtable = $fn->getParam(1);
        $map = $this->context->structFieldMap['__value__'];
        $htPtr = $this->context->getTypeFromString('__hashtable__*');
        $this->context->builder->call(
            $this->context->lookupFunction('__value__valueDelref'),
            $value
        );
        $this->context->builder->store(
            $this->context->getTypeFromString('int8')->constInt(Variable::TYPE_HASHTABLE, false),
            $this->context->builder->structGep($value, $map['type'])
        );
        $ptrField = $this->context->builder->structGep($value, $map['value']);
        $htSlot = $this->context->builder->pointerCast($ptrField, $htPtr->pointerType(0));
        $this->context->builder->store($hashtable, $htSlot);
        $this->context->builder->returnVoid();
    }

    private function lookupStringKeyValue(
        PHPLLVM\Value\Function_ $fn,
        PHPLLVM\BasicBlock $block,
        PHPLLVM\Value $ht,
        PHPLLVM\Value $key
    ): PHPLLVM\Value {
        $htMap = $this->context->structFieldMap['__hashtable__'];
        $nodeMap = $this->context->structFieldMap['__strkey_node__'];
        $head = $this->context->builder->load($this->context->builder->structGep($ht, $htMap['strKeys']));
        $valuePtrType = $this->context->getTypeFromString('__value__*');
        $nodePtrType = $head->typeOf();

        $currentSlot = $this->context->builder->alloca($nodePtrType, 1, 'strkey_current');
        $this->context->builder->store($head, $currentSlot);

        $resultSlot = $this->context->builder->alloca($valuePtrType, 1, 'strkey_lookup_result');
        $this->context->builder->store($valuePtrType->constNull(), $resultSlot);

        $notFound = $fn->appendBasicBlock('strkey_lookup_not_found');
        $loopHead = $fn->appendBasicBlock('strkey_lookup_head');
        $loopBody = $fn->appendBasicBlock('strkey_lookup_body');
        $found = $fn->appendBasicBlock('strkey_lookup_found');
        $done = $fn->appendBasicBlock('strkey_lookup_done');
        $this->context->builder->branch($loopHead);

        $this->context->builder->positionAtEnd($loopHead);
        $node = $this->context->builder->load($currentSlot);
        $isNull = $this->context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $this->context->builder->branchIf($isNull, $notFound, $loopBody);

        $this->context->builder->positionAtEnd($loopBody);
        $nodeKey = $this->context->builder->load($this->context->builder->structGep($node, $nodeMap['key']));
        $cmp = $this->context->builder->call(
            $this->context->lookupFunction('strcmp'),
            $this->stringDataPtr($key),
            $this->stringDataPtr($nodeKey)
        );
        $isMatch = $this->context->builder->icmp(Builder::INT_EQ, $cmp, $cmp->typeOf()->constInt(0, false));
        $next = $fn->appendBasicBlock('strkey_lookup_next');
        $this->context->builder->branchIf($isMatch, $found, $next);

        $this->context->builder->positionAtEnd($found);
        $valField = $this->context->builder->structGep($node, $nodeMap['value']);
        $this->context->builder->store($valField, $resultSlot);
        $this->context->builder->branch($done);

        $this->context->builder->positionAtEnd($next);
        $nextNode = $this->context->builder->load($this->context->builder->structGep($node, $nodeMap['next']));
        $this->context->builder->store($nextNode, $currentSlot);
        $this->context->builder->branch($loopHead);

        $this->context->builder->positionAtEnd($notFound);
        $this->context->builder->branch($done);

        $this->context->builder->positionAtEnd($done);

        return $this->context->builder->load($resultSlot);
    }

    private function implementSortPacked(): void
    {
        $fn = $this->context->lookupFunction('__hashtable__sortPacked');
        $main = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($main);
        $ht = $fn->getParam(0);
        $map = $this->context->structFieldMap['__hashtable__'];
        $sizeT = $this->context->getTypeFromString('size_t');
        $two = $sizeT->constInt(2, false);
        $num = $this->context->builder->load($this->context->builder->structGep($ht, $map['nextFreeElement']));
        $tooSmall = $this->context->builder->icmp(Builder::INT_ULT, $num, $two);
        $done = $fn->appendBasicBlock('sort_done');
        $work = $fn->appendBasicBlock('sort_work');
        $this->context->builder->branchIf($tooSmall, $done, $work);

        $this->context->builder->positionAtEnd($work);
        $zero = $sizeT->constInt(0, false);
        $firstEntry = $this->listEntryAt($ht, $map, $zero);
        $valueMap = $this->context->structFieldMap['__value__'];
        $firstType = $this->context->builder->load(
            $this->context->builder->structGep($firstEntry, $valueMap['type'])
        );
        $i8 = $this->context->getTypeFromString('int8');
        $stringTag = $i8->constInt(Variable::TYPE_STRING, false);
        $longTag = $i8->constInt(Variable::TYPE_NATIVE_LONG, false);
        $isString = $this->context->builder->icmp(Builder::INT_EQ, $firstType, $stringTag);
        $sortStrings = $fn->appendBasicBlock('sort_strings');
        $sortLongs = $fn->appendBasicBlock('sort_longs');
        $this->context->builder->branchIf($isString, $sortStrings, $sortLongs);

        $this->context->builder->positionAtEnd($sortStrings);
        $this->emitBubbleSortStrings($fn, $ht, $map, $num);
        $this->context->builder->branch($done);

        $this->context->builder->positionAtEnd($sortLongs);
        $this->emitBubbleSortLongs($fn, $ht, $map, $num);
        $this->context->builder->branch($done);

        $this->context->builder->positionAtEnd($done);
        $this->context->builder->returnVoid();
    }

    private function emitBubbleSortStrings(
        PHPLLVM\LLVMAbstract\Value\Function_ $fn,
        PHPLLVM\Value $ht,
        array $map,
        PHPLLVM\Value $num
    ): void {
        $sizeT = $this->context->getTypeFromString('size_t');
        $one = $sizeT->constInt(1, false);
        $zero = $sizeT->constInt(0, false);
        $outerSlot = $this->context->builder->alloca($sizeT, 1, 'sort_outer');
        $this->context->builder->store($zero, $outerSlot);
        $outerHead = $fn->appendBasicBlock('sort_str_outer_head');
        $outerBody = $fn->appendBasicBlock('sort_str_outer_body');
        $outerDone = $fn->appendBasicBlock('sort_str_outer_done');
        $this->context->builder->branch($outerHead);

        $this->context->builder->positionAtEnd($outerHead);
        $outer = $this->context->builder->load($outerSlot);
        $outerEnd = $this->context->builder->sub($num, $one);
        $outerAtEnd = $this->context->builder->icmp(Builder::INT_SGE, $outer, $outerEnd);
        $this->context->builder->branchIf($outerAtEnd, $outerDone, $outerBody);

        $this->context->builder->positionAtEnd($outerBody);
        $innerSlot = $this->context->builder->alloca($sizeT, 1, 'sort_inner');
        $this->context->builder->store($zero, $innerSlot);
        $limit = $this->context->builder->sub($num, $outer);
        $limit = $this->context->builder->sub($limit, $one);
        $innerHead = $fn->appendBasicBlock('sort_str_inner_head');
        $innerBody = $fn->appendBasicBlock('sort_str_inner_body');
        $innerDone = $fn->appendBasicBlock('sort_str_inner_done');
        $this->context->builder->branch($innerHead);

        $this->context->builder->positionAtEnd($innerHead);
        $inner = $this->context->builder->load($innerSlot);
        $innerAtEnd = $this->context->builder->icmp(Builder::INT_SGE, $inner, $limit);
        $this->context->builder->branchIf($innerAtEnd, $innerDone, $innerBody);

        $this->context->builder->positionAtEnd($innerBody);
        $nextInner = $this->context->builder->addNoSignedWrap($inner, $one);
        $strA = $this->context->builder->call(
            $this->context->lookupFunction('__hashtable__readStringAt'),
            $ht,
            $inner
        );
        $strB = $this->context->builder->call(
            $this->context->lookupFunction('__hashtable__readStringAt'),
            $ht,
            $nextInner
        );
        $cmp = $this->context->builder->call(
            $this->context->lookupFunction('strcmp'),
            $this->stringDataPtr($strA),
            $this->stringDataPtr($strB)
        );
        $i32 = $this->context->getTypeFromString('int32');
        $needsSwap = $this->context->builder->icmp(Builder::INT_SGT, $cmp, $i32->constInt(0, false));
        $swapBlock = $fn->appendBasicBlock('sort_str_swap');
        $noSwap = $fn->appendBasicBlock('sort_str_no_swap');
        $afterSwap = $fn->appendBasicBlock('sort_str_after_swap');
        $this->context->builder->branchIf($needsSwap, $swapBlock, $noSwap);

        $this->context->builder->positionAtEnd($swapBlock);
        $entryA = $this->listEntryAt($ht, $map, $inner);
        $entryB = $this->listEntryAt($ht, $map, $nextInner);
        $this->context->builder->call($this->context->lookupFunction('__value__writeString'), $entryA, $strB);
        $this->context->builder->call($this->context->lookupFunction('__value__writeString'), $entryB, $strA);
        $this->context->builder->branch($afterSwap);

        $this->context->builder->positionAtEnd($noSwap);
        $this->context->builder->branch($afterSwap);

        $this->context->builder->positionAtEnd($afterSwap);
        $this->context->builder->store($nextInner, $innerSlot);
        $this->context->builder->branch($innerHead);

        $this->context->builder->positionAtEnd($innerDone);
        $this->context->builder->store(
            $this->context->builder->addNoSignedWrap($outer, $one),
            $outerSlot
        );
        $this->context->builder->branch($outerHead);

        $this->context->builder->positionAtEnd($outerDone);
    }

    private function emitBubbleSortLongs(
        PHPLLVM\LLVMAbstract\Value\Function_ $fn,
        PHPLLVM\Value $ht,
        array $map,
        PHPLLVM\Value $num
    ): void {
        $sizeT = $this->context->getTypeFromString('size_t');
        $one = $sizeT->constInt(1, false);
        $zero = $sizeT->constInt(0, false);
        $outerSlot = $this->context->builder->alloca($sizeT, 1, 'sort_long_outer');
        $this->context->builder->store($zero, $outerSlot);
        $outerHead = $fn->appendBasicBlock('sort_long_outer_head');
        $outerBody = $fn->appendBasicBlock('sort_long_outer_body');
        $outerDone = $fn->appendBasicBlock('sort_long_outer_done');
        $this->context->builder->branch($outerHead);

        $this->context->builder->positionAtEnd($outerHead);
        $outer = $this->context->builder->load($outerSlot);
        $outerEnd = $this->context->builder->sub($num, $one);
        $outerAtEnd = $this->context->builder->icmp(Builder::INT_SGE, $outer, $outerEnd);
        $this->context->builder->branchIf($outerAtEnd, $outerDone, $outerBody);

        $this->context->builder->positionAtEnd($outerBody);
        $innerSlot = $this->context->builder->alloca($sizeT, 1, 'sort_long_inner');
        $this->context->builder->store($zero, $innerSlot);
        $limit = $this->context->builder->sub($num, $outer);
        $limit = $this->context->builder->sub($limit, $one);
        $innerHead = $fn->appendBasicBlock('sort_long_inner_head');
        $innerBody = $fn->appendBasicBlock('sort_long_inner_body');
        $innerDone = $fn->appendBasicBlock('sort_long_inner_done');
        $this->context->builder->branch($innerHead);

        $this->context->builder->positionAtEnd($innerHead);
        $inner = $this->context->builder->load($innerSlot);
        $innerAtEnd = $this->context->builder->icmp(Builder::INT_SGE, $inner, $limit);
        $this->context->builder->branchIf($innerAtEnd, $innerDone, $innerBody);

        $this->context->builder->positionAtEnd($innerBody);
        $nextInner = $this->context->builder->addNoSignedWrap($inner, $one);
        $longA = $this->context->builder->call(
            $this->context->lookupFunction('__hashtable__readLongAt'),
            $ht,
            $inner
        );
        $longB = $this->context->builder->call(
            $this->context->lookupFunction('__hashtable__readLongAt'),
            $ht,
            $nextInner
        );
        $needsSwap = $this->context->builder->icmp(Builder::INT_SGT, $longA, $longB);
        $swapBlock = $fn->appendBasicBlock('sort_long_swap');
        $noSwap = $fn->appendBasicBlock('sort_long_no_swap');
        $afterSwap = $fn->appendBasicBlock('sort_long_after_swap');
        $this->context->builder->branchIf($needsSwap, $swapBlock, $noSwap);

        $this->context->builder->positionAtEnd($swapBlock);
        $entryA = $this->listEntryAt($ht, $map, $inner);
        $entryB = $this->listEntryAt($ht, $map, $nextInner);
        $this->context->builder->call($this->context->lookupFunction('__value__writeLong'), $entryA, $longB);
        $this->context->builder->call($this->context->lookupFunction('__value__writeLong'), $entryB, $longA);
        $this->context->builder->branch($afterSwap);

        $this->context->builder->positionAtEnd($noSwap);
        $this->context->builder->branch($afterSwap);

        $this->context->builder->positionAtEnd($afterSwap);
        $this->context->builder->store($nextInner, $innerSlot);
        $this->context->builder->branch($innerHead);

        $this->context->builder->positionAtEnd($innerDone);
        $this->context->builder->store(
            $this->context->builder->addNoSignedWrap($outer, $one),
            $outerSlot
        );
        $this->context->builder->branch($outerHead);

        $this->context->builder->positionAtEnd($outerDone);
    }

    /**
     * @param array<string, int> $map
     */
    private function listEntryAt(PHPLLVM\Value $ht, array $map, PHPLLVM\Value $index): PHPLLVM\Value
    {
        $values = $this->context->builder->load($this->context->builder->structGep($ht, $map['values']));

        return $this->context->builder->inBoundsGep($values, $index);
    }

    private function stringDataPtr(PHPLLVM\Value $str): PHPLLVM\Value
    {
        $map = $this->context->structFieldMap['__string__'];

        return $this->context->builder->structGep($str, $map['value']);
    }

    /**
     * @param array<string, int> $map
     */
    private function incrementNumElements(PHPLLVM\Value $ht): void
    {
        $map = $this->context->structFieldMap['__hashtable__'];
        $sizeT = $this->context->getTypeFromString('size_t');
        $numPtr = $this->context->builder->structGep($ht, $map['numElements']);
        $num = $this->context->builder->load($numPtr);
        $this->context->builder->store(
            $this->context->builder->addNoSignedWrap($num, $sizeT->constInt(1, false)),
            $numPtr
        );
    }

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
