<?php

declare(strict_types=1);

/**
 * Packed-list __hashtable__ for JIT/AOT (integer indices plus string keys for superglobals).
 */

namespace PHPCompiler\JIT\Builtin\Type;

use PHPCompiler\JIT\JitStringCompare;

use PHPCompiler\JIT\Builtin\Refcount;
use PHPCompiler\JIT\Builtin\StringStrcoll;
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
            $this->context->getTypeFromString('int64'),
        );
        $this->context->structFieldMap['__hashtable__'] = [
            'ref' => 0,
            'numElements' => 1,
            'nextFreeElement' => 2,
            'capacity' => 3,
            'values' => 4,
            'strKeys' => 5,
            'objKeys' => 6,
            /** Zend HT internal pointer for key/current/next (#4967, #5504). */
            'internalPointer' => 7,
        ];

        $this->registerFn('__hashtable__alloc', '__hashtable__*', []);
        $this->registerFn('__hashtable__grow', 'void', ['__hashtable__*', 'size_t']);
        $this->registerFn('__hashtable__setLongAt', 'void', ['__hashtable__*', 'size_t', 'int64']);
        $this->registerFn('__hashtable__setDoubleAt', 'void', ['__hashtable__*', 'size_t', 'double']);
        $this->registerFn('__hashtable__setBoolAt', 'void', ['__hashtable__*', 'size_t', 'int1']);
        $this->registerFn('__hashtable__setStringAt', 'void', ['__hashtable__*', 'size_t', '__string__*']);
        $this->registerFn('__hashtable__setHashtableAt', 'void', ['__hashtable__*', 'size_t', '__hashtable__*']);
        $this->registerFn('__hashtable__setObjectAt', 'void', ['__hashtable__*', 'size_t', '__object__*']);
        $this->registerFn('__hashtable__setNullAt', 'void', ['__hashtable__*', 'size_t']);
        $this->registerFn('__hashtable__readLongAt', 'int64', ['__hashtable__*', 'size_t']);
        $this->registerFn('__hashtable__readStringAt', '__string__*', ['__hashtable__*', 'size_t']);
        $this->registerFn('__hashtable__getNumElements', 'size_t', ['__hashtable__*']);
        $this->registerFn('__hashtable__offsetIsSet', 'int1', ['__hashtable__*', 'size_t']);
        $this->registerFn('__hashtable__unsetLongAt', 'void', ['__hashtable__*', 'size_t']);
        $this->registerFn('__hashtable__unsetStringKey', 'void', ['__hashtable__*', '__string__*']);
        $this->registerFn('__hashtable__offsetIsSetStringKey', 'int1', ['__hashtable__*', '__string__*']);
        $this->registerFn('__hashtable__setStringKeyString', 'void', ['__hashtable__*', '__string__*', '__string__*']);
        $this->registerFn('__hashtable__setStringKeyHashtable', 'void', ['__hashtable__*', '__string__*', '__hashtable__*']);
        $this->registerFn('__hashtable__setStringKeyObject', 'void', ['__hashtable__*', '__string__*', '__object__*']);
        $this->registerFn('__hashtable__setStringKeyLong', 'void', ['__hashtable__*', '__string__*', 'int64']);
        $this->registerFn('__hashtable__setStringKeyDouble', 'void', ['__hashtable__*', '__string__*', 'double']);
        $this->registerFn('__hashtable__setStringKeyBool', 'void', ['__hashtable__*', '__string__*', 'int1']);
        $this->registerFn('__hashtable__setStringKeyNull', 'void', ['__hashtable__*', '__string__*']);
        $this->registerFn('__hashtable__peekStringKeyValue', '__value__*', ['__hashtable__*', '__string__*']);
        $this->registerFn('__hashtable__readStringKeyValue', '__value__*', ['__hashtable__*', '__string__*']);
        $this->registerFn('__hashtable__readStringKeyHashtable', '__hashtable__*', ['__hashtable__*', '__string__*']);
        $this->registerFn('__hashtable__readObjectKeyValue', '__value__*', ['__hashtable__*', '__object__*']);
        $this->registerFn('__hashtable__setObjectKeyLong', 'void', ['__hashtable__*', '__object__*', 'int64']);
        $this->registerFn('__hashtable__setObjectKeyObject', 'void', ['__hashtable__*', '__object__*', '__object__*']);
        $this->registerFn('__hashtable__offsetIsSetObjectKey', 'int1', ['__hashtable__*', '__object__*']);
        $this->registerFn('__value__readHashtable', '__hashtable__*', ['__value__*']);
        $this->registerFn('__value__writeHashtable', 'void', ['__value__*', '__hashtable__*']);
        // ksort()/krsort() string-key maps — NestedJIT KeySortJitHelper aborts under thin AOT (#27227 / peer #26975).
        $this->registerFn('__hashtable__sortStringKeys', 'void', ['__hashtable__*']);
        $this->registerFn('__hashtable__sortStringKeysLocale', 'void', ['__hashtable__*']);
        $this->registerFn('__hashtable__sortStringKeysReverse', 'void', ['__hashtable__*']);
        $this->registerFn('__hashtable__sortStringKeyValues', 'void', ['__hashtable__*']);
        $this->registerFn('__hashtable__sortStringKeyValuesLocale', 'void', ['__hashtable__*']);
        $this->registerFn('__hashtable__sortStringKeyValuesNatural', 'void', ['__hashtable__*']);
        $this->registerFn('__hashtable__sortStringKeyValuesNaturalCase', 'void', ['__hashtable__*']);
        $this->registerFn('__hashtable__sortStringKeyValuesReverse', 'void', ['__hashtable__*']);
        // Packed-list sort()/rsort() — NestedJIT SortJitHelper stubs were no-ops (#24010).
        $this->registerFn('__hashtable__sortPacked', 'void', ['__hashtable__*']);
        $this->registerFn('__hashtable__sortPackedReverse', 'void', ['__hashtable__*']);
        // Packed natsort()/natcasesort() — NestedJIT NaturalSortJitHelper aborts under thin AOT (#26975).
        $this->registerFn('__hashtable__sortPackedNatural', 'void', ['__hashtable__*']);
        $this->registerFn('__hashtable__sortPackedNaturalCase', 'void', ['__hashtable__*']);
        // Coupled array_multisort() — NestedJIT MultisortJitHelper aborts under thin AOT (#26908).
        $this->registerFn('__multisort__packed', 'void', ['__hashtable__*', 'int1']);
        $this->pointer = $this->context->getTypeFromString('__hashtable__*');
    }

    /**
     * @param list<string> $paramTypes
     */
    private function registerFn(string $name, string $returnType, array $paramTypes): void
    {
        $params = [];
        foreach ($paramTypes as $t) {
            $params[] = $this->context->getTypeFromString($t);
        }
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
        $this->ensureLibcStrtol();
        \PHPCompiler\JIT\Builtin\StringStrnatcmp::ensureLinked($this->context);
        \PHPCompiler\JIT\Builtin\StringStrnatcasecmp::ensureLinked($this->context);
        $this->implementAlloc();
        $this->implementGrow();
        $this->implementSetLongAt();
        $this->implementSetDoubleAt();
        $this->implementSetBoolAt();
        $this->implementSetStringAt();
        $this->implementSetHashtableAt();
        $this->implementSetObjectAt();
        $this->implementSetNullAt();
        $this->implementReadLongAt();
        $this->implementReadStringAt();
        $this->implementGetNumElements();
        $this->implementOffsetIsSet();
        $this->implementUnsetLongAt();
        $this->implementUnsetStringKey();
        $this->implementSetStringKeyString();
        $this->implementSetStringKeyLong();
        $this->implementSetStringKeyDouble();
        $this->implementSetStringKeyBool();
        $this->implementSetStringKeyNull();
        $this->implementSetStringKeyHashtable();
        $this->implementSetStringKeyObject();
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
        $this->implementSortStringKeys();
        $this->implementSortStringKeysLocale();
        $this->implementSortStringKeysReverse();
        $this->implementSortStringKeyValues();
        $this->implementSortStringKeyValuesLocale();
        $this->implementSortStringKeyValuesNatural();
        $this->implementSortStringKeyValuesNaturalCase();
        $this->implementSortStringKeyValuesReverse();
        $this->implementSortPacked(false);
        $this->implementSortPacked(true);
        $this->implementSortPackedNatural(false);
        $this->implementSortPackedNatural(true);
        $this->implementMultisortPacked();
    }

    private function ensureLibcStrtol(): void
    {
        try {
            $this->context->lookupFunction('strtol');
        } catch (\Throwable $e) {
            $i8p = $this->context->getTypeFromString('int8*');
            $i8pp = $this->context->getTypeFromString('int8**');
            $i32 = $this->context->getTypeFromString('int32');
            $i64 = $this->context->getTypeFromString('int64');
            $ft = $this->context->context->functionType($i64, false, $i8p, $i8pp, $i32);
            $fn = $this->context->module->addFunction('strtol', $ft);
            $this->context->registerFunction('strtol', $fn);
        }
    }

    private function ensureLibcStringCompare(): void
    {
        $i8p = $this->context->getTypeFromString('int8*');
        $i32 = $this->context->getTypeFromString('int32');
        $ft = $this->context->context->functionType($i32, false, $i8p, $i8p);
        try {
            $this->context->lookupFunction('strcmp');
        } catch (\Throwable $e) {
            $fn = $this->context->module->addFunction('strcmp', $ft);
            $this->context->registerFunction('strcmp', $fn);
        }
        try {
            $this->context->lookupFunction('strnatcmp');
        } catch (\Throwable $e) {
            $fn = $this->context->module->addFunction('strnatcmp', $ft);
            $this->context->registerFunction('strnatcmp', $fn);
        }
        try {
            $this->context->lookupFunction('strnatcasecmp');
        } catch (\Throwable $e) {
            $fn = $this->context->module->addFunction('strnatcasecmp', $ft);
            $this->context->registerFunction('strnatcasecmp', $fn);
        }
        try {
            $this->context->lookupFunction(StringStrcoll::ABI_STRCOLL);
        } catch (\Throwable $e) {
            StringStrcoll::ensureLinked($this->context);
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
        $invalidPtr = $this->context->getTypeFromString('int64')->constInt(-1, true);
        $this->context->builder->store(
            $invalidPtr,
            $this->context->builder->structGep($ht, $map['internalPointer'])
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
        $this->context->builder->call(
            $this->context->lookupFunction('__hashtable__grow'),
            $ht,
            $sizeT->constInt(1, false)
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

        // Packed holes must be TYPE_UNDEFINED (0xff), not TYPE_NULL (0). Foreach
        // skips UNDEFINED only; NULL is a real element (#27536 / peer #27581).
        $undefI8 = $this->context->getTypeFromString('int8')->constInt(
            \PHPCompiler\VM\Variable::TYPE_UNDEFINED & 0xff,
            false
        );

        $this->context->builder->positionAtEnd($mallocBb);
        $malloced = $this->context->builder->call($this->context->lookupFunction('__mm__malloc'), $bytes);
        $this->context->intrinsic->memset($malloced, $undefI8, $bytes, false);
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
        $this->context->intrinsic->memset($tailStart, $undefI8, $tailBytes, false);
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
        $wasSet = $this->context->builder->call(
            $this->context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $index
        );
        $this->context->builder->call($this->context->lookupFunction('__hashtable__grow'), $ht, $need);
        $map = $this->context->structFieldMap['__hashtable__'];
        $values = $this->context->builder->load($this->context->builder->structGep($ht, $map['values']));
        $entry = $this->context->builder->inBoundsGep($values, $index);
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeLong'),
            $entry,
            $value
        );
        $this->updateIndexMetadata($ht, $map, $index, $need, $wasSet);
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
        $wasSet = $this->context->builder->call(
            $this->context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $index
        );
        $this->context->builder->call($this->context->lookupFunction('__hashtable__grow'), $ht, $need);
        $map = $this->context->structFieldMap['__hashtable__'];
        $values = $this->context->builder->load($this->context->builder->structGep($ht, $map['values']));
        $entry = $this->context->builder->inBoundsGep($values, $index);
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeDouble'),
            $entry,
            $value
        );
        $this->updateIndexMetadata($ht, $map, $index, $need, $wasSet);
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
        $wasSet = $this->context->builder->call(
            $this->context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $index
        );
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
        $this->updateIndexMetadata($ht, $map, $index, $need, $wasSet);
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
        $wasSet = $this->context->builder->call(
            $this->context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $index
        );
        $this->context->builder->call($this->context->lookupFunction('__hashtable__grow'), $ht, $need);
        $map = $this->context->structFieldMap['__hashtable__'];
        $values = $this->context->builder->load($this->context->builder->structGep($ht, $map['values']));
        $entry = $this->context->builder->inBoundsGep($values, $index);
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeString'),
            $entry,
            $str
        );
        $this->updateIndexMetadata($ht, $map, $index, $need, $wasSet);
        $this->context->builder->returnVoid();
    }

    private function implementSetHashtableAt(): void
    {
        $fn = $this->context->lookupFunction('__hashtable__setHashtableAt');
        $block = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($block);
        $ht = $fn->getParam(0);
        $index = $fn->getParam(1);
        $child = $fn->getParam(2);
        $sizeT = $this->context->getTypeFromString('size_t');
        $one = $sizeT->constInt(1, false);
        $need = $this->context->builder->addNoSignedWrap($index, $one);
        $wasSet = $this->context->builder->call(
            $this->context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $index
        );
        $this->context->builder->call($this->context->lookupFunction('__hashtable__grow'), $ht, $need);
        $map = $this->context->structFieldMap['__hashtable__'];
        $values = $this->context->builder->load($this->context->builder->structGep($ht, $map['values']));
        $entry = $this->context->builder->inBoundsGep($values, $index);
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeHashtable'),
            $entry,
            $child
        );
        $this->updateIndexMetadata($ht, $map, $index, $need, $wasSet);
        $this->context->builder->returnVoid();
    }

    private function implementSetObjectAt(): void
    {
        $fn = $this->context->lookupFunction('__hashtable__setObjectAt');
        $block = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($block);
        $ht = $fn->getParam(0);
        $index = $fn->getParam(1);
        $obj = $fn->getParam(2);
        $sizeT = $this->context->getTypeFromString('size_t');
        $one = $sizeT->constInt(1, false);
        $need = $this->context->builder->addNoSignedWrap($index, $one);
        $wasSet = $this->context->builder->call(
            $this->context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $index
        );
        $this->context->builder->call($this->context->lookupFunction('__hashtable__grow'), $ht, $need);
        $map = $this->context->structFieldMap['__hashtable__'];
        $values = $this->context->builder->load($this->context->builder->structGep($ht, $map['values']));
        $entry = $this->context->builder->inBoundsGep($values, $index);
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeObject'),
            $entry,
            $obj
        );
        $this->updateIndexMetadata($ht, $map, $index, $need, $wasSet);
        $this->context->builder->returnVoid();
    }

    private function implementSetNullAt(): void
    {
        $fn = $this->context->lookupFunction('__hashtable__setNullAt');
        $block = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($block);
        $ht = $fn->getParam(0);
        $index = $fn->getParam(1);
        $sizeT = $this->context->getTypeFromString('size_t');
        $one = $sizeT->constInt(1, false);
        $need = $this->context->builder->addNoSignedWrap($index, $one);
        $wasSet = $this->context->builder->call(
            $this->context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $index
        );
        $this->context->builder->call($this->context->lookupFunction('__hashtable__grow'), $ht, $need);
        $map = $this->context->structFieldMap['__hashtable__'];
        $values = $this->context->builder->load($this->context->builder->structGep($ht, $map['values']));
        $entry = $this->context->builder->inBoundsGep($values, $index);
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeNull'),
            $entry
        );
        $this->updateIndexMetadata($ht, $map, $index, $need, $wasSet);
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
        $i8 = $this->context->getTypeFromString('int8');
        // isset / wasSet: both TYPE_NULL and packed TYPE_UNDEFINED holes are unset
        // (#27536 — grow fills UNDEFINED; explicit null stays isset-false).
        $isNull = $this->context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(\PHPCompiler\VM\Variable::TYPE_NULL, false)
        );
        $isUndef = $this->context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(\PHPCompiler\VM\Variable::TYPE_UNDEFINED & 0xff, false)
        );
        $set = $this->context->builder->not($this->context->builder->or($isNull, $isUndef));
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
        $isMatch = JitStringCompare::identical($this->context, $key, $nodeKey);
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
        $newNode = $this->mallocZeroedNode($nodeType);
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
        $currentHead = $this->loadStrKeysHead($headSlot);
        $isEmpty = $this->context->builder->icmp(Builder::INT_EQ, $currentHead, $currentHead->typeOf()->constNull());
        $this->context->builder->branchIf($isEmpty, $emptyHead, $tailWalk);

        $this->context->builder->positionAtEnd($tailWalk);
        $walkNode = $this->context->builder->phi($currentHead->typeOf());
        $walkNode->addIncoming($currentHead, $tail);
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
        $isMatch = JitStringCompare::identical($this->context, $key, $nodeKey);
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
        $newNode = $this->mallocZeroedNode($nodeType);
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
        $this->context->builder->store(
            $newNode->typeOf()->constNull(),
            $this->context->builder->structGep($newNode, $nodeMap['next'])
        );
        $tail = $fn->appendBasicBlock('strkey_ht_tail');
        $emptyHead = $fn->appendBasicBlock('strkey_ht_empty_head');
        $tailWalk = $fn->appendBasicBlock('strkey_ht_tail_walk');
        $tailDone = $fn->appendBasicBlock('strkey_ht_tail_done');
        $this->context->builder->branch($tail);

        $this->context->builder->positionAtEnd($tail);
        $currentHead = $this->loadStrKeysHead($headSlot);
        $isEmpty = $this->context->builder->icmp(Builder::INT_EQ, $currentHead, $currentHead->typeOf()->constNull());
        $this->context->builder->branchIf($isEmpty, $emptyHead, $tailWalk);

        $this->context->builder->positionAtEnd($tailWalk);
        $walkNode = $this->context->builder->phi($currentHead->typeOf());
        $walkNode->addIncoming($currentHead, $tail);
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

    private function implementSetStringKeyObject(): void
    {
        $fn = $this->context->lookupFunction('__hashtable__setStringKeyObject');
        $block = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($block);
        $ht = $fn->getParam(0);
        $key = $fn->getParam(1);
        $child = $fn->getParam(2);

        $htMap = $this->context->structFieldMap['__hashtable__'];
        $nodeMap = $this->context->structFieldMap['__strkey_node__'];
        $headSlot = $this->context->builder->structGep($ht, $htMap['strKeys']);
        $head = $this->context->builder->load($headSlot);

        $done = $fn->appendBasicBlock('strkey_obj_done');
        $prepend = $fn->appendBasicBlock('strkey_obj_prepend');
        $loopHead = $fn->appendBasicBlock('strkey_obj_head');
        $loopBody = $fn->appendBasicBlock('strkey_obj_body');
        $this->context->builder->branch($loopHead);

        $this->context->builder->positionAtEnd($loopHead);
        $node = $this->context->builder->phi($head->typeOf());
        $node->addIncoming($head, $block);
        $isNull = $this->context->builder->icmp(Builder::INT_EQ, $node, $node->typeOf()->constNull());
        $this->context->builder->branchIf($isNull, $prepend, $loopBody);

        $this->context->builder->positionAtEnd($loopBody);
        $nodeKey = $this->context->builder->load($this->context->builder->structGep($node, $nodeMap['key']));
        $isMatch = JitStringCompare::identical($this->context, $key, $nodeKey);
        $update = $fn->appendBasicBlock('strkey_obj_update');
        $next = $fn->appendBasicBlock('strkey_obj_next');
        $this->context->builder->branchIf($isMatch, $update, $next);

        $this->context->builder->positionAtEnd($update);
        $valField = $this->context->builder->structGep($node, $nodeMap['value']);
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeObject'),
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
        $newNode = $this->mallocZeroedNode($nodeType);
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
            $this->context->lookupFunction('__value__writeObject'),
            $this->context->builder->structGep($newNode, $nodeMap['value']),
            $child
        );
        $this->context->builder->store(
            $newNode->typeOf()->constNull(),
            $this->context->builder->structGep($newNode, $nodeMap['next'])
        );
        $tail = $fn->appendBasicBlock('strkey_obj_tail');
        $emptyHead = $fn->appendBasicBlock('strkey_obj_empty_head');
        $tailWalk = $fn->appendBasicBlock('strkey_obj_tail_walk');
        $tailDone = $fn->appendBasicBlock('strkey_obj_tail_done');
        $this->context->builder->branch($tail);

        $this->context->builder->positionAtEnd($tail);
        $currentHead = $this->loadStrKeysHead($headSlot);
        $isEmpty = $this->context->builder->icmp(Builder::INT_EQ, $currentHead, $currentHead->typeOf()->constNull());
        $this->context->builder->branchIf($isEmpty, $emptyHead, $tailWalk);

        $this->context->builder->positionAtEnd($tailWalk);
        $walkNode = $this->context->builder->phi($currentHead->typeOf());
        $walkNode->addIncoming($currentHead, $tail);
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
        $isMatch = JitStringCompare::identical($this->context, $key, $nodeKey);
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
        $newNode = $this->mallocZeroedNode($nodeType);
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
        $this->context->builder->store(
            $newNode->typeOf()->constNull(),
            $this->context->builder->structGep($newNode, $nodeMap['next'])
        );
        $tail = $fn->appendBasicBlock('strkey_long_tail');
        $emptyHead = $fn->appendBasicBlock('strkey_long_empty_head');
        $tailWalk = $fn->appendBasicBlock('strkey_long_tail_walk');
        $tailDone = $fn->appendBasicBlock('strkey_long_tail_done');
        $this->context->builder->branch($tail);

        $this->context->builder->positionAtEnd($tail);
        $currentHead = $this->loadStrKeysHead($headSlot);
        $isEmpty = $this->context->builder->icmp(Builder::INT_EQ, $currentHead, $currentHead->typeOf()->constNull());
        $this->context->builder->branchIf($isEmpty, $emptyHead, $tailWalk);

        $this->context->builder->positionAtEnd($tailWalk);
        $walkNode = $this->context->builder->phi($currentHead->typeOf());
        $walkNode->addIncoming($currentHead, $tail);
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

    private function implementSetStringKeyDouble(): void
    {
        $fn = $this->context->lookupFunction('__hashtable__setStringKeyDouble');
        $block = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($block);
        $ht = $fn->getParam(0);
        $key = $fn->getParam(1);
        $double = $fn->getParam(2);

        $htMap = $this->context->structFieldMap['__hashtable__'];
        $nodeMap = $this->context->structFieldMap['__strkey_node__'];
        $headSlot = $this->context->builder->structGep($ht, $htMap['strKeys']);
        $head = $this->context->builder->load($headSlot);

        $done = $fn->appendBasicBlock('strkey_double_done');
        $prepend = $fn->appendBasicBlock('strkey_double_prepend');
        $loopHead = $fn->appendBasicBlock('strkey_double_head');
        $loopBody = $fn->appendBasicBlock('strkey_double_body');
        $this->context->builder->branch($loopHead);

        $this->context->builder->positionAtEnd($loopHead);
        $node = $this->context->builder->phi($head->typeOf());
        $node->addIncoming($head, $block);
        $isNull = $this->context->builder->icmp(Builder::INT_EQ, $node, $node->typeOf()->constNull());
        $this->context->builder->branchIf($isNull, $prepend, $loopBody);

        $this->context->builder->positionAtEnd($loopBody);
        $nodeKey = $this->context->builder->load($this->context->builder->structGep($node, $nodeMap['key']));
        $isMatch = JitStringCompare::identical($this->context, $key, $nodeKey);
        $update = $fn->appendBasicBlock('strkey_double_update');
        $next = $fn->appendBasicBlock('strkey_double_next');
        $this->context->builder->branchIf($isMatch, $update, $next);

        $this->context->builder->positionAtEnd($update);
        $valField = $this->context->builder->structGep($node, $nodeMap['value']);
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeDouble'),
            $valField,
            $double
        );
        $this->context->builder->branch($done);

        $this->context->builder->positionAtEnd($next);
        $nextNode = $this->context->builder->load($this->context->builder->structGep($node, $nodeMap['next']));
        $this->context->builder->branch($loopHead);
        $node->addIncoming($nextNode, $next);

        $this->context->builder->positionAtEnd($prepend);
        $nodeType = $this->context->getTypeFromString('__strkey_node__');
        $newNode = $this->mallocZeroedNode($nodeType);
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
            $this->context->lookupFunction('__value__writeDouble'),
            $this->context->builder->structGep($newNode, $nodeMap['value']),
            $double
        );
        $this->context->builder->store(
            $newNode->typeOf()->constNull(),
            $this->context->builder->structGep($newNode, $nodeMap['next'])
        );
        $tail = $fn->appendBasicBlock('strkey_double_tail');
        $emptyHead = $fn->appendBasicBlock('strkey_double_empty_head');
        $tailWalk = $fn->appendBasicBlock('strkey_double_tail_walk');
        $tailDone = $fn->appendBasicBlock('strkey_double_tail_done');
        $this->context->builder->branch($tail);

        $this->context->builder->positionAtEnd($tail);
        $currentHead = $this->loadStrKeysHead($headSlot);
        $isEmpty = $this->context->builder->icmp(Builder::INT_EQ, $currentHead, $currentHead->typeOf()->constNull());
        $this->context->builder->branchIf($isEmpty, $emptyHead, $tailWalk);

        $this->context->builder->positionAtEnd($tailWalk);
        $walkNode = $this->context->builder->phi($currentHead->typeOf());
        $walkNode->addIncoming($currentHead, $tail);
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
        $isMatch = JitStringCompare::identical($this->context, $key, $nodeKey);
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
        $newNode = $this->mallocZeroedNode($nodeType);
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
        $this->context->builder->store(
            $newNode->typeOf()->constNull(),
            $this->context->builder->structGep($newNode, $nodeMap['next'])
        );
        $tail = $fn->appendBasicBlock('strkey_bool_tail');
        $emptyHead = $fn->appendBasicBlock('strkey_bool_empty_head');
        $tailWalk = $fn->appendBasicBlock('strkey_bool_tail_walk');
        $tailDone = $fn->appendBasicBlock('strkey_bool_tail_done');
        $this->context->builder->branch($tail);

        $this->context->builder->positionAtEnd($tail);
        $currentHead = $this->loadStrKeysHead($headSlot);
        $isEmpty = $this->context->builder->icmp(Builder::INT_EQ, $currentHead, $currentHead->typeOf()->constNull());
        $this->context->builder->branchIf($isEmpty, $emptyHead, $tailWalk);

        $this->context->builder->positionAtEnd($tailWalk);
        $walkNode = $this->context->builder->phi($currentHead->typeOf());
        $walkNode->addIncoming($currentHead, $tail);
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

    private function implementSetStringKeyNull(): void
    {
        $fn = $this->context->lookupFunction('__hashtable__setStringKeyNull');
        $block = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($block);
        $ht = $fn->getParam(0);
        $key = $fn->getParam(1);

        $htMap = $this->context->structFieldMap['__hashtable__'];
        $nodeMap = $this->context->structFieldMap['__strkey_node__'];
        $headSlot = $this->context->builder->structGep($ht, $htMap['strKeys']);
        $head = $this->context->builder->load($headSlot);

        $done = $fn->appendBasicBlock('strkey_null_done');
        $prepend = $fn->appendBasicBlock('strkey_null_prepend');
        $loopHead = $fn->appendBasicBlock('strkey_null_head');
        $loopBody = $fn->appendBasicBlock('strkey_null_body');
        $this->context->builder->branch($loopHead);

        $this->context->builder->positionAtEnd($loopHead);
        $node = $this->context->builder->phi($head->typeOf());
        $node->addIncoming($head, $block);
        $isNull = $this->context->builder->icmp(Builder::INT_EQ, $node, $node->typeOf()->constNull());
        $this->context->builder->branchIf($isNull, $prepend, $loopBody);

        $this->context->builder->positionAtEnd($loopBody);
        $nodeKey = $this->context->builder->load($this->context->builder->structGep($node, $nodeMap['key']));
        $isMatch = JitStringCompare::identical($this->context, $key, $nodeKey);
        $update = $fn->appendBasicBlock('strkey_null_update');
        $next = $fn->appendBasicBlock('strkey_null_next');
        $this->context->builder->branchIf($isMatch, $update, $next);

        $this->context->builder->positionAtEnd($update);
        $valField = $this->context->builder->structGep($node, $nodeMap['value']);
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeNull'),
            $valField
        );
        $this->context->builder->branch($done);

        $this->context->builder->positionAtEnd($next);
        $nextNode = $this->context->builder->load($this->context->builder->structGep($node, $nodeMap['next']));
        $this->context->builder->branch($loopHead);
        $node->addIncoming($nextNode, $next);

        $this->context->builder->positionAtEnd($prepend);
        $nodeType = $this->context->getTypeFromString('__strkey_node__');
        $newNode = $this->mallocZeroedNode($nodeType);
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
            $this->context->lookupFunction('__value__writeNull'),
            $this->context->builder->structGep($newNode, $nodeMap['value'])
        );
        $this->context->builder->store(
            $newNode->typeOf()->constNull(),
            $this->context->builder->structGep($newNode, $nodeMap['next'])
        );
        $tail = $fn->appendBasicBlock('strkey_null_tail');
        $emptyHead = $fn->appendBasicBlock('strkey_null_empty_head');
        $tailWalk = $fn->appendBasicBlock('strkey_null_tail_walk');
        $tailDone = $fn->appendBasicBlock('strkey_null_tail_done');
        $this->context->builder->branch($tail);

        $this->context->builder->positionAtEnd($tail);
        $currentHead = $this->loadStrKeysHead($headSlot);
        $isEmpty = $this->context->builder->icmp(Builder::INT_EQ, $currentHead, $currentHead->typeOf()->constNull());
        $this->context->builder->branchIf($isEmpty, $emptyHead, $tailWalk);

        $this->context->builder->positionAtEnd($tailWalk);
        $walkNode = $this->context->builder->phi($currentHead->typeOf());
        $walkNode->addIncoming($currentHead, $tail);
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
        $newNode = $this->mallocZeroedNode($nodeType);
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
        $newNode = $this->mallocZeroedNode($nodeType);
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
        $i8 = $this->context->getTypeFromString('int8');
        $typeByte = $this->context->builder->load($this->context->builder->structGep($value, $map['type']));
        // Mask IS_REFCOUNTED — writers may store TYPE_HASHTABLE (135) or kind 7 (#26977 /
        // JitValueBox copyFromPointer). Unmasked EQ missed kind 7 → null HT → NestedJIT abort.
        $kind = $this->context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isHt = $this->context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_HASHTABLE & 0x7f, false)
        );
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
        // Match writeObject: retain the HT for the value-box owner (#24226 e08_spread).
        $this->context->builder->call(
            $this->context->lookupFunction('__ref__addref'),
            $this->context->builder->pointerCast(
                $hashtable,
                $this->context->getTypeFromString('__ref__virtual*')
            )
        );
        $this->context->builder->returnVoid();
        $this->context->builder->clearInsertionPosition();
    }

    private function lookupStringKeyValue(
        PHPLLVM\Value\Function_ $fn,
        PHPLLVM\BasicBlock $block,
        PHPLLVM\Value $ht,
        PHPLLVM\Value $key
    ): PHPLLVM\Value {
        $valuePtrType = $this->context->getTypeFromString('__value__*');

        $resultSlot = $this->context->builder->alloca($valuePtrType, 1, 'strkey_lookup_result');
        $this->context->builder->store($valuePtrType->constNull(), $resultSlot);

        $notFound = $fn->appendBasicBlock('strkey_lookup_not_found');
        $init = $fn->appendBasicBlock('strkey_lookup_init');
        $loopHead = $fn->appendBasicBlock('strkey_lookup_head');
        $loopBody = $fn->appendBasicBlock('strkey_lookup_body');
        $found = $fn->appendBasicBlock('strkey_lookup_found');
        $done = $fn->appendBasicBlock('strkey_lookup_done');

        // Uninitialized/null hashtable: avoid segfault in native AOT (#1514, #1761).
        $htNull = $this->context->builder->icmp(Builder::INT_EQ, $ht, $ht->typeOf()->constNull());
        $this->context->builder->branchIf($htNull, $notFound, $init);

        $this->context->builder->positionAtEnd($init);
        $htMap = $this->context->structFieldMap['__hashtable__'];
        $nodeMap = $this->context->structFieldMap['__strkey_node__'];
        $head = $this->context->builder->load($this->context->builder->structGep($ht, $htMap['strKeys']));
        $nodePtrType = $head->typeOf();

        $currentSlot = $this->context->builder->alloca($nodePtrType, 1, 'strkey_current');
        $this->context->builder->store($head, $currentSlot);
        $this->context->builder->branch($loopHead);

        $this->context->builder->positionAtEnd($loopHead);
        $node = $this->context->builder->load($currentSlot);
        $isNull = $this->context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $this->context->builder->branchIf($isNull, $notFound, $loopBody);

        $this->context->builder->positionAtEnd($loopBody);
        $nodeKey = $this->context->builder->load($this->context->builder->structGep($node, $nodeMap['key']));
        $isMatch = JitStringCompare::identical($this->context, $key, $nodeKey);
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
        $this->tryLookupPackedIntFromStringKey($fn, $notFound, $ht, $key, $resultSlot, $done);

        $this->context->builder->positionAtEnd($done);

        return $this->context->builder->load($resultSlot);
    }

    /**
     * Zend numeric-string → int key fallback when string-key chain misses (#3679).
     */
    private function tryLookupPackedIntFromStringKey(
        PHPLLVM\Value\Function_ $fn,
        PHPLLVM\BasicBlock $entryBlock,
        PHPLLVM\Value $ht,
        PHPLLVM\Value $key,
        PHPLLVM\Value $resultSlot,
        PHPLLVM\BasicBlock $done
    ): void {
        $tryInt = $fn->appendBasicBlock('strkey_lookup_try_int');
        $parseInt = $fn->appendBasicBlock('strkey_lookup_parse_int');
        $intFound = $fn->appendBasicBlock('strkey_lookup_int_found');
        $skipInt = $fn->appendBasicBlock('strkey_lookup_skip_int');

        $this->context->builder->positionAtEnd($entryBlock);
        $this->context->builder->branch($tryInt);

        $this->context->builder->positionAtEnd($tryInt);
        $isIntKey = $this->stringIsIntegerNumericKey($key);
        $this->context->builder->branchIf($isIntKey, $parseInt, $skipInt);

        $this->context->builder->positionAtEnd($parseInt);
        $sizeT = $this->context->getTypeFromString('size_t');
        $i8p = $this->context->getTypeFromString('int8*');
        $i64 = $this->context->getTypeFromString('int64');
        $endPtrSlot = $this->context->builder->alloca($i8p, 1, 'strkey_lookup_strtol_end');
        $this->context->builder->store($i8p->constNull(), $endPtrSlot);
        $parsed = $this->context->builder->call(
            $this->context->lookupFunction('strtol'),
            $this->stringDataPtr($key),
            $endPtrSlot,
            $this->context->getTypeFromString('int32')->constInt(10, false)
        );
        $index = $this->context->builder->truncOrBitCast($parsed, $sizeT);
        $isSet = $this->context->builder->call(
            $this->context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $index
        );
        $this->context->builder->branchIf($isSet, $intFound, $skipInt);

        $this->context->builder->positionAtEnd($intFound);
        $htMap = $this->context->structFieldMap['__hashtable__'];
        $entry = $this->listEntryAt($ht, $htMap, $index);
        $this->context->builder->store($entry, $resultSlot);
        $this->context->builder->branch($done);

        $this->context->builder->positionAtEnd($skipInt);
        $this->context->builder->branch($done);
    }

    /** True when strtol(base 10) consumes the entire __string__ (integer numeric string). */
    private function stringIsIntegerNumericKey(PHPLLVM\Value $strPtr): PHPLLVM\Value
    {
        $map = $this->context->structFieldMap['__string__'];
        $len = $this->context->builder->load(
            $this->context->builder->structGep($strPtr, $map['length'])
        );
        $i64 = $this->context->getTypeFromString('int64');
        $i8p = $this->context->getTypeFromString('int8*');
        $isEmpty = $this->context->builder->icmp(Builder::INT_EQ, $len, $len->typeOf()->constInt(0, false));

        $charPtr = $this->context->builder->structGep($strPtr, $map['value']);
        $endPtrSlot = $this->context->builder->alloca($i8p, 1, 'strkey_is_int_end');
        $this->context->builder->store($i8p->constNull(), $endPtrSlot);
        $this->context->builder->call(
            $this->context->lookupFunction('strtol'),
            $charPtr,
            $endPtrSlot,
            $this->context->getTypeFromString('int32')->constInt(10, false)
        );
        $endPtr = $this->context->builder->load($endPtrSlot);
        $endOffset = $this->context->builder->sub(
            $this->context->builder->ptrToInt($endPtr, $i64),
            $this->context->builder->ptrToInt($charPtr, $i64)
        );
        $consumedAll = $this->context->builder->icmp(Builder::INT_EQ, $endOffset, $len);

        return $this->context->builder->select($isEmpty, $this->context->constantFromBool(false), $consumedAll);
    }

    private function implementSortStringKeys(): void
    {
        $fn = $this->context->lookupFunction('__hashtable__sortStringKeys');
        $main = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($main);
        $ht = $fn->getParam(0);
        $htMap = $this->context->structFieldMap['__hashtable__'];
        $nodeMap = $this->context->structFieldMap['__strkey_node__'];
        $headSlot = $this->context->builder->structGep($ht, $htMap['strKeys']);
        $nodePtrType = $this->context->getTypeFromString('__strkey_node__*');
        $nullNode = $nodePtrType->constNull();
        $i1 = $this->context->getTypeFromString('int1');
        $i32 = $this->context->getTypeFromString('int32');

        $head = $this->context->builder->load($headSlot);
        $done = $fn->appendBasicBlock('ksort_str_done');
        $work = $fn->appendBasicBlock('ksort_str_work');
        $headIsNull = $this->context->builder->icmp(Builder::INT_EQ, $head, $nullNode);
        $this->context->builder->branchIf($headIsNull, $done, $work);

        $this->context->builder->positionAtEnd($work);
        $headNext = $this->context->builder->load($this->context->builder->structGep($head, $nodeMap['next']));
        $singleNode = $this->context->builder->icmp(Builder::INT_EQ, $headNext, $nullNode);
        $passStart = $fn->appendBasicBlock('ksort_str_pass');
        $this->context->builder->branchIf($singleNode, $done, $passStart);

        $this->context->builder->positionAtEnd($passStart);
        $swappedSlot = $this->context->builder->alloca($i1, 1, 'ksort_str_swapped');
        $this->context->builder->store($i1->constInt(1, false), $swappedSlot);

        $passHead = $fn->appendBasicBlock('ksort_str_pass_head');
        $passBody = $fn->appendBasicBlock('ksort_str_pass_body');
        $passExit = $fn->appendBasicBlock('ksort_str_pass_exit');
        $this->context->builder->branch($passHead);

        $this->context->builder->positionAtEnd($passHead);
        $didSwap = $this->context->builder->load($swappedSlot);
        $this->context->builder->branchIf($didSwap, $passBody, $done);

        $this->context->builder->positionAtEnd($passBody);
        $this->context->builder->store($i1->constInt(0, false), $swappedSlot);
        $prevSlot = $this->context->builder->alloca($nodePtrType, 1, 'ksort_str_prev');
        $curSlot = $this->context->builder->alloca($nodePtrType, 1, 'ksort_str_cur');
        $this->context->builder->store($nullNode, $prevSlot);
        $this->context->builder->store($this->loadStrKeysHead($headSlot), $curSlot);

        $walkHead = $fn->appendBasicBlock('ksort_str_walk_head');
        $walkBody = $fn->appendBasicBlock('ksort_str_walk_body');
        $this->context->builder->branch($walkHead);

        $this->context->builder->positionAtEnd($walkHead);
        $cur = $this->context->builder->load($curSlot);
        $curIsNull = $this->context->builder->icmp(Builder::INT_EQ, $cur, $nullNode);
        $this->context->builder->branchIf($curIsNull, $passExit, $walkBody);

        $this->context->builder->positionAtEnd($walkBody);
        $next = $this->context->builder->load($this->context->builder->structGep($cur, $nodeMap['next']));
        $nextIsNull = $this->context->builder->icmp(Builder::INT_EQ, $next, $nullNode);
        $advance = $fn->appendBasicBlock('ksort_str_advance');
        $compare = $fn->appendBasicBlock('ksort_str_compare');
        $this->context->builder->branchIf($nextIsNull, $passExit, $compare);

        $this->context->builder->positionAtEnd($compare);
        $keyCur = $this->context->builder->load($this->context->builder->structGep($cur, $nodeMap['key']));
        $keyNext = $this->context->builder->load($this->context->builder->structGep($next, $nodeMap['key']));
        $cmp = $this->context->builder->call(
            $this->context->lookupFunction('strcmp'),
            $this->stringDataPtr($keyCur),
            $this->stringDataPtr($keyNext)
        );
        $needsSwap = $this->context->builder->icmp(Builder::INT_SGT, $cmp, $i32->constInt(0, false));
        $swapBlock = $fn->appendBasicBlock('ksort_str_swap');
        $this->context->builder->branchIf($needsSwap, $swapBlock, $advance);

        $this->context->builder->positionAtEnd($swapBlock);
        $prev = $this->context->builder->load($prevSlot);
        $hasPrev = $this->context->builder->icmp(Builder::INT_NE, $prev, $nullNode);
        $updateHead = $fn->appendBasicBlock('ksort_str_update_head');
        $updatePrev = $fn->appendBasicBlock('ksort_str_update_prev');
        $afterLink = $fn->appendBasicBlock('ksort_str_after_link');
        $this->context->builder->branchIf($hasPrev, $updatePrev, $updateHead);

        $this->context->builder->positionAtEnd($updateHead);
        $this->context->builder->store($next, $headSlot);
        $this->context->builder->branch($afterLink);

        $this->context->builder->positionAtEnd($updatePrev);
        $this->context->builder->store(
            $next,
            $this->context->builder->structGep($prev, $nodeMap['next'])
        );
        $this->context->builder->branch($afterLink);

        $this->context->builder->positionAtEnd($afterLink);
        $nextNext = $this->context->builder->load($this->context->builder->structGep($next, $nodeMap['next']));
        $this->context->builder->store($nextNext, $this->context->builder->structGep($cur, $nodeMap['next']));
        $this->context->builder->store($cur, $this->context->builder->structGep($next, $nodeMap['next']));
        $this->context->builder->store($i1->constInt(1, false), $swappedSlot);
        $this->context->builder->store($next, $curSlot);
        $this->context->builder->branch($walkHead);

        $this->context->builder->positionAtEnd($advance);
        $this->context->builder->store($cur, $prevSlot);
        $this->context->builder->store($next, $curSlot);
        $this->context->builder->branch($walkHead);

        $this->context->builder->positionAtEnd($passExit);
        $this->context->builder->branch($passHead);

        $this->context->builder->positionAtEnd($done);
        $this->context->builder->returnVoid();
    }

    private function implementSortStringKeysLocale(): void
    {
        $fn = $this->context->lookupFunction('__hashtable__sortStringKeysLocale');
        $main = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($main);
        $ht = $fn->getParam(0);
        $htMap = $this->context->structFieldMap['__hashtable__'];
        $nodeMap = $this->context->structFieldMap['__strkey_node__'];
        $headSlot = $this->context->builder->structGep($ht, $htMap['strKeys']);
        $nodePtrType = $this->context->getTypeFromString('__strkey_node__*');
        $nullNode = $nodePtrType->constNull();
        $i1 = $this->context->getTypeFromString('int1');
        $i32 = $this->context->getTypeFromString('int32');

        $head = $this->context->builder->load($headSlot);
        $done = $fn->appendBasicBlock('ksort_str_locale_done');
        $work = $fn->appendBasicBlock('ksort_str_locale_work');
        $headIsNull = $this->context->builder->icmp(Builder::INT_EQ, $head, $nullNode);
        $this->context->builder->branchIf($headIsNull, $done, $work);

        $this->context->builder->positionAtEnd($work);
        $headNext = $this->context->builder->load($this->context->builder->structGep($head, $nodeMap['next']));
        $singleNode = $this->context->builder->icmp(Builder::INT_EQ, $headNext, $nullNode);
        $passStart = $fn->appendBasicBlock('ksort_str_locale_pass');
        $this->context->builder->branchIf($singleNode, $done, $passStart);

        $this->context->builder->positionAtEnd($passStart);
        $swappedSlot = $this->context->builder->alloca($i1, 1, 'ksort_str_locale_swapped');
        $this->context->builder->store($i1->constInt(1, false), $swappedSlot);

        $passHead = $fn->appendBasicBlock('ksort_str_locale_pass_head');
        $passBody = $fn->appendBasicBlock('ksort_str_locale_pass_body');
        $passExit = $fn->appendBasicBlock('ksort_str_locale_pass_exit');
        $this->context->builder->branch($passHead);

        $this->context->builder->positionAtEnd($passHead);
        $didSwap = $this->context->builder->load($swappedSlot);
        $this->context->builder->branchIf($didSwap, $passBody, $done);

        $this->context->builder->positionAtEnd($passBody);
        $this->context->builder->store($i1->constInt(0, false), $swappedSlot);
        $prevSlot = $this->context->builder->alloca($nodePtrType, 1, 'ksort_str_locale_prev');
        $curSlot = $this->context->builder->alloca($nodePtrType, 1, 'ksort_str_locale_cur');
        $this->context->builder->store($nullNode, $prevSlot);
        $this->context->builder->store($this->loadStrKeysHead($headSlot), $curSlot);

        $walkHead = $fn->appendBasicBlock('ksort_str_locale_walk_head');
        $walkBody = $fn->appendBasicBlock('ksort_str_locale_walk_body');
        $this->context->builder->branch($walkHead);

        $this->context->builder->positionAtEnd($walkHead);
        $cur = $this->context->builder->load($curSlot);
        $curIsNull = $this->context->builder->icmp(Builder::INT_EQ, $cur, $nullNode);
        $this->context->builder->branchIf($curIsNull, $passExit, $walkBody);

        $this->context->builder->positionAtEnd($walkBody);
        $next = $this->context->builder->load($this->context->builder->structGep($cur, $nodeMap['next']));
        $nextIsNull = $this->context->builder->icmp(Builder::INT_EQ, $next, $nullNode);
        $advance = $fn->appendBasicBlock('ksort_str_locale_advance');
        $compare = $fn->appendBasicBlock('ksort_str_locale_compare');
        $this->context->builder->branchIf($nextIsNull, $passExit, $compare);

        $this->context->builder->positionAtEnd($compare);
        $keyCur = $this->context->builder->load($this->context->builder->structGep($cur, $nodeMap['key']));
        $keyNext = $this->context->builder->load($this->context->builder->structGep($next, $nodeMap['key']));
        $cmp = $this->context->builder->call(
            $this->context->lookupFunction('strcoll'),
            $this->stringDataPtr($keyCur),
            $this->stringDataPtr($keyNext)
        );
        $needsSwap = $this->context->builder->icmp(Builder::INT_SGT, $cmp, $i32->constInt(0, false));
        $swapBlock = $fn->appendBasicBlock('ksort_str_locale_swap');
        $this->context->builder->branchIf($needsSwap, $swapBlock, $advance);

        $this->context->builder->positionAtEnd($swapBlock);
        $prev = $this->context->builder->load($prevSlot);
        $hasPrev = $this->context->builder->icmp(Builder::INT_NE, $prev, $nullNode);
        $updateHead = $fn->appendBasicBlock('ksort_str_locale_update_head');
        $updatePrev = $fn->appendBasicBlock('ksort_str_locale_update_prev');
        $afterLink = $fn->appendBasicBlock('ksort_str_locale_after_link');
        $this->context->builder->branchIf($hasPrev, $updatePrev, $updateHead);

        $this->context->builder->positionAtEnd($updateHead);
        $this->context->builder->store($next, $headSlot);
        $this->context->builder->branch($afterLink);

        $this->context->builder->positionAtEnd($updatePrev);
        $this->context->builder->store(
            $next,
            $this->context->builder->structGep($prev, $nodeMap['next'])
        );
        $this->context->builder->branch($afterLink);

        $this->context->builder->positionAtEnd($afterLink);
        $nextNext = $this->context->builder->load($this->context->builder->structGep($next, $nodeMap['next']));
        $this->context->builder->store($nextNext, $this->context->builder->structGep($cur, $nodeMap['next']));
        $this->context->builder->store($cur, $this->context->builder->structGep($next, $nodeMap['next']));
        $this->context->builder->store($i1->constInt(1, false), $swappedSlot);
        $this->context->builder->store($next, $curSlot);
        $this->context->builder->branch($walkHead);

        $this->context->builder->positionAtEnd($advance);
        $this->context->builder->store($cur, $prevSlot);
        $this->context->builder->store($next, $curSlot);
        $this->context->builder->branch($walkHead);

        $this->context->builder->positionAtEnd($passExit);
        $this->context->builder->branch($passHead);

        $this->context->builder->positionAtEnd($done);
        $this->context->builder->returnVoid();
    }

    private function implementSortStringKeysReverse(): void
    {
        $fn = $this->context->lookupFunction('__hashtable__sortStringKeysReverse');
        $main = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($main);
        $ht = $fn->getParam(0);
        $htMap = $this->context->structFieldMap['__hashtable__'];
        $nodeMap = $this->context->structFieldMap['__strkey_node__'];
        $headSlot = $this->context->builder->structGep($ht, $htMap['strKeys']);
        $nodePtrType = $this->context->getTypeFromString('__strkey_node__*');
        $nullNode = $nodePtrType->constNull();
        $i1 = $this->context->getTypeFromString('int1');
        $i32 = $this->context->getTypeFromString('int32');

        $head = $this->context->builder->load($headSlot);
        $done = $fn->appendBasicBlock('krsort_str_done');
        $work = $fn->appendBasicBlock('krsort_str_work');
        $headIsNull = $this->context->builder->icmp(Builder::INT_EQ, $head, $nullNode);
        $this->context->builder->branchIf($headIsNull, $done, $work);

        $this->context->builder->positionAtEnd($work);
        $headNext = $this->context->builder->load($this->context->builder->structGep($head, $nodeMap['next']));
        $singleNode = $this->context->builder->icmp(Builder::INT_EQ, $headNext, $nullNode);
        $passStart = $fn->appendBasicBlock('krsort_str_pass');
        $this->context->builder->branchIf($singleNode, $done, $passStart);

        $this->context->builder->positionAtEnd($passStart);
        $swappedSlot = $this->context->builder->alloca($i1, 1, 'krsort_str_swapped');
        $this->context->builder->store($i1->constInt(1, false), $swappedSlot);

        $passHead = $fn->appendBasicBlock('krsort_str_pass_head');
        $passBody = $fn->appendBasicBlock('krsort_str_pass_body');
        $passExit = $fn->appendBasicBlock('krsort_str_pass_exit');
        $this->context->builder->branch($passHead);

        $this->context->builder->positionAtEnd($passHead);
        $didSwap = $this->context->builder->load($swappedSlot);
        $this->context->builder->branchIf($didSwap, $passBody, $done);

        $this->context->builder->positionAtEnd($passBody);
        $this->context->builder->store($i1->constInt(0, false), $swappedSlot);
        $prevSlot = $this->context->builder->alloca($nodePtrType, 1, 'krsort_str_prev');
        $curSlot = $this->context->builder->alloca($nodePtrType, 1, 'krsort_str_cur');
        $this->context->builder->store($nullNode, $prevSlot);
        $this->context->builder->store($this->loadStrKeysHead($headSlot), $curSlot);

        $walkHead = $fn->appendBasicBlock('krsort_str_walk_head');
        $walkBody = $fn->appendBasicBlock('krsort_str_walk_body');
        $this->context->builder->branch($walkHead);

        $this->context->builder->positionAtEnd($walkHead);
        $cur = $this->context->builder->load($curSlot);
        $curIsNull = $this->context->builder->icmp(Builder::INT_EQ, $cur, $nullNode);
        $this->context->builder->branchIf($curIsNull, $passExit, $walkBody);

        $this->context->builder->positionAtEnd($walkBody);
        $next = $this->context->builder->load($this->context->builder->structGep($cur, $nodeMap['next']));
        $nextIsNull = $this->context->builder->icmp(Builder::INT_EQ, $next, $nullNode);
        $advance = $fn->appendBasicBlock('krsort_str_advance');
        $compare = $fn->appendBasicBlock('krsort_str_compare');
        $this->context->builder->branchIf($nextIsNull, $passExit, $compare);

        $this->context->builder->positionAtEnd($compare);
        $keyCur = $this->context->builder->load($this->context->builder->structGep($cur, $nodeMap['key']));
        $keyNext = $this->context->builder->load($this->context->builder->structGep($next, $nodeMap['key']));
        $cmp = $this->context->builder->call(
            $this->context->lookupFunction('strcmp'),
            $this->stringDataPtr($keyCur),
            $this->stringDataPtr($keyNext)
        );
        $needsSwap = $this->context->builder->icmp(Builder::INT_SLT, $cmp, $i32->constInt(0, false));
        $swapBlock = $fn->appendBasicBlock('krsort_str_swap');
        $this->context->builder->branchIf($needsSwap, $swapBlock, $advance);

        $this->context->builder->positionAtEnd($swapBlock);
        $prev = $this->context->builder->load($prevSlot);
        $hasPrev = $this->context->builder->icmp(Builder::INT_NE, $prev, $nullNode);
        $updateHead = $fn->appendBasicBlock('krsort_str_update_head');
        $updatePrev = $fn->appendBasicBlock('krsort_str_update_prev');
        $afterLink = $fn->appendBasicBlock('krsort_str_after_link');
        $this->context->builder->branchIf($hasPrev, $updatePrev, $updateHead);

        $this->context->builder->positionAtEnd($updateHead);
        $this->context->builder->store($next, $headSlot);
        $this->context->builder->branch($afterLink);

        $this->context->builder->positionAtEnd($updatePrev);
        $this->context->builder->store(
            $next,
            $this->context->builder->structGep($prev, $nodeMap['next'])
        );
        $this->context->builder->branch($afterLink);

        $this->context->builder->positionAtEnd($afterLink);
        $nextNext = $this->context->builder->load($this->context->builder->structGep($next, $nodeMap['next']));
        $this->context->builder->store($nextNext, $this->context->builder->structGep($cur, $nodeMap['next']));
        $this->context->builder->store($cur, $this->context->builder->structGep($next, $nodeMap['next']));
        $this->context->builder->store($i1->constInt(1, false), $swappedSlot);
        $this->context->builder->store($next, $curSlot);
        $this->context->builder->branch($walkHead);

        $this->context->builder->positionAtEnd($advance);
        $this->context->builder->store($cur, $prevSlot);
        $this->context->builder->store($next, $curSlot);
        $this->context->builder->branch($walkHead);

        $this->context->builder->positionAtEnd($passExit);
        $this->context->builder->branch($passHead);

        $this->context->builder->positionAtEnd($done);
        $this->context->builder->returnVoid();
    }


    private function implementSortStringKeyValues(): void
    {
        $fn = $this->context->lookupFunction('__hashtable__sortStringKeyValues');
        $main = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($main);
        $ht = $fn->getParam(0);
        $htMap = $this->context->structFieldMap['__hashtable__'];
        $nodeMap = $this->context->structFieldMap['__strkey_node__'];
        $valueMap = $this->context->structFieldMap['__value__'];
        $headSlot = $this->context->builder->structGep($ht, $htMap['strKeys']);
        $nodePtrType = $this->context->getTypeFromString('__strkey_node__*');
        $nullNode = $nodePtrType->constNull();
        $i1 = $this->context->getTypeFromString('int1');
        $i32 = $this->context->getTypeFromString('int32');
        $i8 = $this->context->getTypeFromString('int8');
        $stringTag = $i8->constInt(Variable::TYPE_STRING, false);

        $head = $this->context->builder->load($headSlot);
        $done = $fn->appendBasicBlock('asort_val_done');
        $work = $fn->appendBasicBlock('asort_val_work');
        $headIsNull = $this->context->builder->icmp(Builder::INT_EQ, $head, $nullNode);
        $this->context->builder->branchIf($headIsNull, $done, $work);

        $this->context->builder->positionAtEnd($work);
        $headNext = $this->context->builder->load($this->context->builder->structGep($head, $nodeMap['next']));
        $singleNode = $this->context->builder->icmp(Builder::INT_EQ, $headNext, $nullNode);
        $passStart = $fn->appendBasicBlock('asort_val_pass');
        $this->context->builder->branchIf($singleNode, $done, $passStart);

        $this->context->builder->positionAtEnd($passStart);
        $swappedSlot = $this->context->builder->alloca($i1, 1, 'asort_val_swapped');
        $this->context->builder->store($i1->constInt(1, false), $swappedSlot);

        $passHead = $fn->appendBasicBlock('asort_val_pass_head');
        $passBody = $fn->appendBasicBlock('asort_val_pass_body');
        $passExit = $fn->appendBasicBlock('asort_val_pass_exit');
        $this->context->builder->branch($passHead);

        $this->context->builder->positionAtEnd($passHead);
        $didSwap = $this->context->builder->load($swappedSlot);
        $this->context->builder->branchIf($didSwap, $passBody, $done);

        $this->context->builder->positionAtEnd($passBody);
        $this->context->builder->store($i1->constInt(0, false), $swappedSlot);
        $prevSlot = $this->context->builder->alloca($nodePtrType, 1, 'asort_val_prev');
        $curSlot = $this->context->builder->alloca($nodePtrType, 1, 'asort_val_cur');
        $needsSwapSlot = $this->context->builder->alloca($i1, 1, 'asort_val_needs_swap');
        $this->context->builder->store($nullNode, $prevSlot);
        $this->context->builder->store($this->loadStrKeysHead($headSlot), $curSlot);

        $walkHead = $fn->appendBasicBlock('asort_val_walk_head');
        $walkBody = $fn->appendBasicBlock('asort_val_walk_body');
        $this->context->builder->branch($walkHead);

        $this->context->builder->positionAtEnd($walkHead);
        $cur = $this->context->builder->load($curSlot);
        $curIsNull = $this->context->builder->icmp(Builder::INT_EQ, $cur, $nullNode);
        $this->context->builder->branchIf($curIsNull, $passExit, $walkBody);

        $this->context->builder->positionAtEnd($walkBody);
        $next = $this->context->builder->load($this->context->builder->structGep($cur, $nodeMap['next']));
        $nextIsNull = $this->context->builder->icmp(Builder::INT_EQ, $next, $nullNode);
        $advance = $fn->appendBasicBlock('asort_val_advance');
        $compare = $fn->appendBasicBlock('asort_val_compare');
        $this->context->builder->branchIf($nextIsNull, $passExit, $compare);

        $this->context->builder->positionAtEnd($compare);
        $valCur = $this->context->builder->structGep($cur, $nodeMap['value']);
        $valNext = $this->context->builder->structGep($next, $nodeMap['value']);
        $typeCur = $this->context->builder->load($this->context->builder->structGep($valCur, $valueMap['type']));
        $isString = $this->context->builder->icmp(Builder::INT_EQ, $typeCur, $stringTag);
        $cmpStr = $fn->appendBasicBlock('asort_val_cmp_str');
        $cmpLong = $fn->appendBasicBlock('asort_val_cmp_long');
        $cmpDone = $fn->appendBasicBlock('asort_val_cmp_done');
        $this->context->builder->branchIf($isString, $cmpStr, $cmpLong);

        $this->context->builder->positionAtEnd($cmpStr);
        $strCur = $this->context->builder->call(
            $this->context->lookupFunction('__value__readString'),
            $valCur
        );
        $strNext = $this->context->builder->call(
            $this->context->lookupFunction('__value__readString'),
            $valNext
        );
        $cmp = JitStringCompare::strcmp($this->context, $strCur, $strNext);
        $i64 = $this->context->getTypeFromString('int64');
        $this->context->builder->store(
            $this->context->builder->icmp(Builder::INT_SGT, $cmp, $i64->constInt(0, false)),
            $needsSwapSlot
        );
        $this->context->builder->branch($cmpDone);

        $this->context->builder->positionAtEnd($cmpLong);
        $longCur = $this->context->builder->call(
            $this->context->lookupFunction('__value__readLong'),
            $valCur
        );
        $longNext = $this->context->builder->call(
            $this->context->lookupFunction('__value__readLong'),
            $valNext
        );
        $this->context->builder->store(
            $this->context->builder->icmp(Builder::INT_SGT, $longCur, $longNext),
            $needsSwapSlot
        );
        $this->context->builder->branch($cmpDone);

        $this->context->builder->positionAtEnd($cmpDone);
        $needsSwap = $this->context->builder->load($needsSwapSlot);
        $swapBlock = $fn->appendBasicBlock('asort_val_swap');
        $this->context->builder->branchIf($needsSwap, $swapBlock, $advance);

        $this->context->builder->positionAtEnd($swapBlock);
        $prev = $this->context->builder->load($prevSlot);
        $hasPrev = $this->context->builder->icmp(Builder::INT_NE, $prev, $nullNode);
        $updateHead = $fn->appendBasicBlock('asort_val_update_head');
        $updatePrev = $fn->appendBasicBlock('asort_val_update_prev');
        $afterLink = $fn->appendBasicBlock('asort_val_after_link');
        $this->context->builder->branchIf($hasPrev, $updatePrev, $updateHead);

        $this->context->builder->positionAtEnd($updateHead);
        $this->context->builder->store($next, $headSlot);
        $this->context->builder->branch($afterLink);

        $this->context->builder->positionAtEnd($updatePrev);
        $this->context->builder->store(
            $next,
            $this->context->builder->structGep($prev, $nodeMap['next'])
        );
        $this->context->builder->branch($afterLink);

        $this->context->builder->positionAtEnd($afterLink);
        $nextNext = $this->context->builder->load($this->context->builder->structGep($next, $nodeMap['next']));
        $this->context->builder->store($nextNext, $this->context->builder->structGep($cur, $nodeMap['next']));
        $this->context->builder->store($cur, $this->context->builder->structGep($next, $nodeMap['next']));
        $this->context->builder->store($i1->constInt(1, false), $swappedSlot);
        $this->context->builder->store($next, $curSlot);
        $this->context->builder->branch($walkHead);

        $this->context->builder->positionAtEnd($advance);
        $this->context->builder->store($cur, $prevSlot);
        $this->context->builder->store($next, $curSlot);
        $this->context->builder->branch($walkHead);

        $this->context->builder->positionAtEnd($passExit);
        $this->context->builder->branch($passHead);

        $this->context->builder->positionAtEnd($done);
        $this->context->builder->returnVoid();
    }

    private function implementSortStringKeyValuesLocale(): void
    {
        $fn = $this->context->lookupFunction('__hashtable__sortStringKeyValuesLocale');
        $main = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($main);
        $ht = $fn->getParam(0);
        $htMap = $this->context->structFieldMap['__hashtable__'];
        $nodeMap = $this->context->structFieldMap['__strkey_node__'];
        $valueMap = $this->context->structFieldMap['__value__'];
        $headSlot = $this->context->builder->structGep($ht, $htMap['strKeys']);
        $nodePtrType = $this->context->getTypeFromString('__strkey_node__*');
        $nullNode = $nodePtrType->constNull();
        $i1 = $this->context->getTypeFromString('int1');
        $i32 = $this->context->getTypeFromString('int32');
        $i8 = $this->context->getTypeFromString('int8');
        $stringTag = $i8->constInt(Variable::TYPE_STRING, false);

        $head = $this->context->builder->load($headSlot);
        $done = $fn->appendBasicBlock('asort_val_locale_done');
        $work = $fn->appendBasicBlock('asort_val_locale_work');
        $headIsNull = $this->context->builder->icmp(Builder::INT_EQ, $head, $nullNode);
        $this->context->builder->branchIf($headIsNull, $done, $work);

        $this->context->builder->positionAtEnd($work);
        $headNext = $this->context->builder->load($this->context->builder->structGep($head, $nodeMap['next']));
        $singleNode = $this->context->builder->icmp(Builder::INT_EQ, $headNext, $nullNode);
        $passStart = $fn->appendBasicBlock('asort_val_locale_pass');
        $this->context->builder->branchIf($singleNode, $done, $passStart);

        $this->context->builder->positionAtEnd($passStart);
        $swappedSlot = $this->context->builder->alloca($i1, 1, 'asort_val_locale_swapped');
        $this->context->builder->store($i1->constInt(1, false), $swappedSlot);

        $passHead = $fn->appendBasicBlock('asort_val_locale_pass_head');
        $passBody = $fn->appendBasicBlock('asort_val_locale_pass_body');
        $passExit = $fn->appendBasicBlock('asort_val_locale_pass_exit');
        $this->context->builder->branch($passHead);

        $this->context->builder->positionAtEnd($passHead);
        $didSwap = $this->context->builder->load($swappedSlot);
        $this->context->builder->branchIf($didSwap, $passBody, $done);

        $this->context->builder->positionAtEnd($passBody);
        $this->context->builder->store($i1->constInt(0, false), $swappedSlot);
        $prevSlot = $this->context->builder->alloca($nodePtrType, 1, 'asort_val_locale_prev');
        $curSlot = $this->context->builder->alloca($nodePtrType, 1, 'asort_val_locale_cur');
        $needsSwapSlot = $this->context->builder->alloca($i1, 1, 'asort_val_locale_needs_swap');
        $this->context->builder->store($nullNode, $prevSlot);
        $this->context->builder->store($this->loadStrKeysHead($headSlot), $curSlot);

        $walkHead = $fn->appendBasicBlock('asort_val_locale_walk_head');
        $walkBody = $fn->appendBasicBlock('asort_val_locale_walk_body');
        $this->context->builder->branch($walkHead);

        $this->context->builder->positionAtEnd($walkHead);
        $cur = $this->context->builder->load($curSlot);
        $curIsNull = $this->context->builder->icmp(Builder::INT_EQ, $cur, $nullNode);
        $this->context->builder->branchIf($curIsNull, $passExit, $walkBody);

        $this->context->builder->positionAtEnd($walkBody);
        $next = $this->context->builder->load($this->context->builder->structGep($cur, $nodeMap['next']));
        $nextIsNull = $this->context->builder->icmp(Builder::INT_EQ, $next, $nullNode);
        $advance = $fn->appendBasicBlock('asort_val_locale_advance');
        $compare = $fn->appendBasicBlock('asort_val_locale_compare');
        $this->context->builder->branchIf($nextIsNull, $passExit, $compare);

        $this->context->builder->positionAtEnd($compare);
        $valCur = $this->context->builder->structGep($cur, $nodeMap['value']);
        $valNext = $this->context->builder->structGep($next, $nodeMap['value']);
        $typeCur = $this->context->builder->load($this->context->builder->structGep($valCur, $valueMap['type']));
        $isString = $this->context->builder->icmp(Builder::INT_EQ, $typeCur, $stringTag);
        $cmpStr = $fn->appendBasicBlock('asort_val_locale_cmp_str');
        $cmpLong = $fn->appendBasicBlock('asort_val_locale_cmp_long');
        $cmpDone = $fn->appendBasicBlock('asort_val_locale_cmp_done');
        $this->context->builder->branchIf($isString, $cmpStr, $cmpLong);

        $this->context->builder->positionAtEnd($cmpStr);
        $strCur = $this->context->builder->call(
            $this->context->lookupFunction('__value__readString'),
            $valCur
        );
        $strNext = $this->context->builder->call(
            $this->context->lookupFunction('__value__readString'),
            $valNext
        );
        $cmp = $this->context->builder->call(
            $this->context->lookupFunction(StringStrcoll::ABI_STRCOLL),
            $this->stringDataPtr($strCur),
            $this->stringDataPtr($strNext)
        );
        $this->context->builder->store(
            $this->context->builder->icmp(Builder::INT_SGT, $cmp, $i32->constInt(0, false)),
            $needsSwapSlot
        );
        $this->context->builder->branch($cmpDone);

        $this->context->builder->positionAtEnd($cmpLong);
        $longCur = $this->context->builder->call(
            $this->context->lookupFunction('__value__readLong'),
            $valCur
        );
        $longNext = $this->context->builder->call(
            $this->context->lookupFunction('__value__readLong'),
            $valNext
        );
        $this->context->builder->store(
            $this->context->builder->icmp(Builder::INT_SGT, $longCur, $longNext),
            $needsSwapSlot
        );
        $this->context->builder->branch($cmpDone);

        $this->context->builder->positionAtEnd($cmpDone);
        $needsSwap = $this->context->builder->load($needsSwapSlot);
        $swapBlock = $fn->appendBasicBlock('asort_val_locale_swap');
        $this->context->builder->branchIf($needsSwap, $swapBlock, $advance);

        $this->context->builder->positionAtEnd($swapBlock);
        $prev = $this->context->builder->load($prevSlot);
        $hasPrev = $this->context->builder->icmp(Builder::INT_NE, $prev, $nullNode);
        $updateHead = $fn->appendBasicBlock('asort_val_locale_update_head');
        $updatePrev = $fn->appendBasicBlock('asort_val_locale_update_prev');
        $afterLink = $fn->appendBasicBlock('asort_val_locale_after_link');
        $this->context->builder->branchIf($hasPrev, $updatePrev, $updateHead);

        $this->context->builder->positionAtEnd($updateHead);
        $this->context->builder->store($next, $headSlot);
        $this->context->builder->branch($afterLink);

        $this->context->builder->positionAtEnd($updatePrev);
        $this->context->builder->store(
            $next,
            $this->context->builder->structGep($prev, $nodeMap['next'])
        );
        $this->context->builder->branch($afterLink);

        $this->context->builder->positionAtEnd($afterLink);
        $nextNext = $this->context->builder->load($this->context->builder->structGep($next, $nodeMap['next']));
        $this->context->builder->store($nextNext, $this->context->builder->structGep($cur, $nodeMap['next']));
        $this->context->builder->store($cur, $this->context->builder->structGep($next, $nodeMap['next']));
        $this->context->builder->store($i1->constInt(1, false), $swappedSlot);
        $this->context->builder->store($next, $curSlot);
        $this->context->builder->branch($walkHead);

        $this->context->builder->positionAtEnd($advance);
        $this->context->builder->store($cur, $prevSlot);
        $this->context->builder->store($next, $curSlot);
        $this->context->builder->branch($walkHead);

        $this->context->builder->positionAtEnd($passExit);
        $this->context->builder->branch($passHead);

        $this->context->builder->positionAtEnd($done);
        $this->context->builder->returnVoid();
    }

    private function implementSortStringKeyValuesNatural(): void
    {
        $fn = $this->context->lookupFunction('__hashtable__sortStringKeyValuesNatural');
        $main = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($main);
        $ht = $fn->getParam(0);
        $htMap = $this->context->structFieldMap['__hashtable__'];
        $nodeMap = $this->context->structFieldMap['__strkey_node__'];
        $valueMap = $this->context->structFieldMap['__value__'];
        $headSlot = $this->context->builder->structGep($ht, $htMap['strKeys']);
        $nodePtrType = $this->context->getTypeFromString('__strkey_node__*');
        $nullNode = $nodePtrType->constNull();
        $i1 = $this->context->getTypeFromString('int1');
        $i32 = $this->context->getTypeFromString('int32');
        $i8 = $this->context->getTypeFromString('int8');
        $stringTag = $i8->constInt(Variable::TYPE_STRING, false);

        $head = $this->context->builder->load($headSlot);
        $done = $fn->appendBasicBlock('natsort_val_done');
        $work = $fn->appendBasicBlock('natsort_val_work');
        $headIsNull = $this->context->builder->icmp(Builder::INT_EQ, $head, $nullNode);
        $this->context->builder->branchIf($headIsNull, $done, $work);

        $this->context->builder->positionAtEnd($work);
        $headNext = $this->context->builder->load($this->context->builder->structGep($head, $nodeMap['next']));
        $singleNode = $this->context->builder->icmp(Builder::INT_EQ, $headNext, $nullNode);
        $passStart = $fn->appendBasicBlock('natsort_val_pass');
        $this->context->builder->branchIf($singleNode, $done, $passStart);

        $this->context->builder->positionAtEnd($passStart);
        $swappedSlot = $this->context->builder->alloca($i1, 1, 'natsort_val_swapped');
        $this->context->builder->store($i1->constInt(1, false), $swappedSlot);

        $passHead = $fn->appendBasicBlock('natsort_val_pass_head');
        $passBody = $fn->appendBasicBlock('natsort_val_pass_body');
        $passExit = $fn->appendBasicBlock('natsort_val_pass_exit');
        $this->context->builder->branch($passHead);

        $this->context->builder->positionAtEnd($passHead);
        $didSwap = $this->context->builder->load($swappedSlot);
        $this->context->builder->branchIf($didSwap, $passBody, $done);

        $this->context->builder->positionAtEnd($passBody);
        $this->context->builder->store($i1->constInt(0, false), $swappedSlot);
        $prevSlot = $this->context->builder->alloca($nodePtrType, 1, 'natsort_val_prev');
        $curSlot = $this->context->builder->alloca($nodePtrType, 1, 'natsort_val_cur');
        $needsSwapSlot = $this->context->builder->alloca($i1, 1, 'natsort_val_needs_swap');
        $this->context->builder->store($nullNode, $prevSlot);
        $this->context->builder->store($this->loadStrKeysHead($headSlot), $curSlot);

        $walkHead = $fn->appendBasicBlock('natsort_val_walk_head');
        $walkBody = $fn->appendBasicBlock('natsort_val_walk_body');
        $this->context->builder->branch($walkHead);

        $this->context->builder->positionAtEnd($walkHead);
        $cur = $this->context->builder->load($curSlot);
        $curIsNull = $this->context->builder->icmp(Builder::INT_EQ, $cur, $nullNode);
        $this->context->builder->branchIf($curIsNull, $passExit, $walkBody);

        $this->context->builder->positionAtEnd($walkBody);
        $next = $this->context->builder->load($this->context->builder->structGep($cur, $nodeMap['next']));
        $nextIsNull = $this->context->builder->icmp(Builder::INT_EQ, $next, $nullNode);
        $advance = $fn->appendBasicBlock('natsort_val_advance');
        $compare = $fn->appendBasicBlock('natsort_val_compare');
        $this->context->builder->branchIf($nextIsNull, $passExit, $compare);

        $this->context->builder->positionAtEnd($compare);
        $valCur = $this->context->builder->structGep($cur, $nodeMap['value']);
        $valNext = $this->context->builder->structGep($next, $nodeMap['value']);
        $typeCur = $this->context->builder->load($this->context->builder->structGep($valCur, $valueMap['type']));
        $isString = $this->context->builder->icmp(Builder::INT_EQ, $typeCur, $stringTag);
        $cmpStr = $fn->appendBasicBlock('natsort_val_cmp_str');
        $cmpLong = $fn->appendBasicBlock('natsort_val_cmp_long');
        $cmpDone = $fn->appendBasicBlock('natsort_val_cmp_done');
        $this->context->builder->branchIf($isString, $cmpStr, $cmpLong);

        $this->context->builder->positionAtEnd($cmpStr);
        $strCur = $this->context->builder->call(
            $this->context->lookupFunction('__value__readString'),
            $valCur
        );
        $strNext = $this->context->builder->call(
            $this->context->lookupFunction('__value__readString'),
            $valNext
        );
        $cmp = $this->context->builder->call(
            $this->context->lookupFunction('strnatcmp'),
            $this->stringDataPtr($strCur),
            $this->stringDataPtr($strNext)
        );
        $this->context->builder->store(
            $this->context->builder->icmp(Builder::INT_SGT, $cmp, $i32->constInt(0, false)),
            $needsSwapSlot
        );
        $this->context->builder->branch($cmpDone);

        $this->context->builder->positionAtEnd($cmpLong);
        $longCur = $this->context->builder->call(
            $this->context->lookupFunction('__value__readLong'),
            $valCur
        );
        $longNext = $this->context->builder->call(
            $this->context->lookupFunction('__value__readLong'),
            $valNext
        );
        $this->context->builder->store(
            $this->context->builder->icmp(Builder::INT_SGT, $longCur, $longNext),
            $needsSwapSlot
        );
        $this->context->builder->branch($cmpDone);

        $this->context->builder->positionAtEnd($cmpDone);
        $needsSwap = $this->context->builder->load($needsSwapSlot);
        $swapBlock = $fn->appendBasicBlock('natsort_val_swap');
        $this->context->builder->branchIf($needsSwap, $swapBlock, $advance);

        $this->context->builder->positionAtEnd($swapBlock);
        $prev = $this->context->builder->load($prevSlot);
        $hasPrev = $this->context->builder->icmp(Builder::INT_NE, $prev, $nullNode);
        $updateHead = $fn->appendBasicBlock('natsort_val_update_head');
        $updatePrev = $fn->appendBasicBlock('natsort_val_update_prev');
        $afterLink = $fn->appendBasicBlock('natsort_val_after_link');
        $this->context->builder->branchIf($hasPrev, $updatePrev, $updateHead);

        $this->context->builder->positionAtEnd($updateHead);
        $this->context->builder->store($next, $headSlot);
        $this->context->builder->branch($afterLink);

        $this->context->builder->positionAtEnd($updatePrev);
        $this->context->builder->store(
            $next,
            $this->context->builder->structGep($prev, $nodeMap['next'])
        );
        $this->context->builder->branch($afterLink);

        $this->context->builder->positionAtEnd($afterLink);
        $nextNext = $this->context->builder->load($this->context->builder->structGep($next, $nodeMap['next']));
        $this->context->builder->store($nextNext, $this->context->builder->structGep($cur, $nodeMap['next']));
        $this->context->builder->store($cur, $this->context->builder->structGep($next, $nodeMap['next']));
        $this->context->builder->store($i1->constInt(1, false), $swappedSlot);
        $this->context->builder->store($next, $curSlot);
        $this->context->builder->branch($walkHead);

        $this->context->builder->positionAtEnd($advance);
        $this->context->builder->store($cur, $prevSlot);
        $this->context->builder->store($next, $curSlot);
        $this->context->builder->branch($walkHead);

        $this->context->builder->positionAtEnd($passExit);
        $this->context->builder->branch($passHead);

        $this->context->builder->positionAtEnd($done);
        $this->context->builder->returnVoid();
    }

    private function implementSortStringKeyValuesNaturalCase(): void
    {
        $fn = $this->context->lookupFunction('__hashtable__sortStringKeyValuesNaturalCase');
        $main = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($main);
        $ht = $fn->getParam(0);
        $htMap = $this->context->structFieldMap['__hashtable__'];
        $nodeMap = $this->context->structFieldMap['__strkey_node__'];
        $valueMap = $this->context->structFieldMap['__value__'];
        $headSlot = $this->context->builder->structGep($ht, $htMap['strKeys']);
        $nodePtrType = $this->context->getTypeFromString('__strkey_node__*');
        $nullNode = $nodePtrType->constNull();
        $i1 = $this->context->getTypeFromString('int1');
        $i32 = $this->context->getTypeFromString('int32');
        $i8 = $this->context->getTypeFromString('int8');
        $stringTag = $i8->constInt(Variable::TYPE_STRING, false);

        $head = $this->context->builder->load($headSlot);
        $done = $fn->appendBasicBlock('natcasesort_val_done');
        $work = $fn->appendBasicBlock('natcasesort_val_work');
        $headIsNull = $this->context->builder->icmp(Builder::INT_EQ, $head, $nullNode);
        $this->context->builder->branchIf($headIsNull, $done, $work);

        $this->context->builder->positionAtEnd($work);
        $headNext = $this->context->builder->load($this->context->builder->structGep($head, $nodeMap['next']));
        $singleNode = $this->context->builder->icmp(Builder::INT_EQ, $headNext, $nullNode);
        $passStart = $fn->appendBasicBlock('natcasesort_val_pass');
        $this->context->builder->branchIf($singleNode, $done, $passStart);

        $this->context->builder->positionAtEnd($passStart);
        $swappedSlot = $this->context->builder->alloca($i1, 1, 'natcasesort_val_swapped');
        $this->context->builder->store($i1->constInt(1, false), $swappedSlot);

        $passHead = $fn->appendBasicBlock('natcasesort_val_pass_head');
        $passBody = $fn->appendBasicBlock('natcasesort_val_pass_body');
        $passExit = $fn->appendBasicBlock('natcasesort_val_pass_exit');
        $this->context->builder->branch($passHead);

        $this->context->builder->positionAtEnd($passHead);
        $didSwap = $this->context->builder->load($swappedSlot);
        $this->context->builder->branchIf($didSwap, $passBody, $done);

        $this->context->builder->positionAtEnd($passBody);
        $this->context->builder->store($i1->constInt(0, false), $swappedSlot);
        $prevSlot = $this->context->builder->alloca($nodePtrType, 1, 'natcasesort_val_prev');
        $curSlot = $this->context->builder->alloca($nodePtrType, 1, 'natcasesort_val_cur');
        $needsSwapSlot = $this->context->builder->alloca($i1, 1, 'natcasesort_val_needs_swap');
        $this->context->builder->store($nullNode, $prevSlot);
        $this->context->builder->store($this->loadStrKeysHead($headSlot), $curSlot);

        $walkHead = $fn->appendBasicBlock('natcasesort_val_walk_head');
        $walkBody = $fn->appendBasicBlock('natcasesort_val_walk_body');
        $this->context->builder->branch($walkHead);

        $this->context->builder->positionAtEnd($walkHead);
        $cur = $this->context->builder->load($curSlot);
        $curIsNull = $this->context->builder->icmp(Builder::INT_EQ, $cur, $nullNode);
        $this->context->builder->branchIf($curIsNull, $passExit, $walkBody);

        $this->context->builder->positionAtEnd($walkBody);
        $next = $this->context->builder->load($this->context->builder->structGep($cur, $nodeMap['next']));
        $nextIsNull = $this->context->builder->icmp(Builder::INT_EQ, $next, $nullNode);
        $advance = $fn->appendBasicBlock('natcasesort_val_advance');
        $compare = $fn->appendBasicBlock('natcasesort_val_compare');
        $this->context->builder->branchIf($nextIsNull, $passExit, $compare);

        $this->context->builder->positionAtEnd($compare);
        $valCur = $this->context->builder->structGep($cur, $nodeMap['value']);
        $valNext = $this->context->builder->structGep($next, $nodeMap['value']);
        $typeCur = $this->context->builder->load($this->context->builder->structGep($valCur, $valueMap['type']));
        $isString = $this->context->builder->icmp(Builder::INT_EQ, $typeCur, $stringTag);
        $cmpStr = $fn->appendBasicBlock('natcasesort_val_cmp_str');
        $cmpLong = $fn->appendBasicBlock('natcasesort_val_cmp_long');
        $cmpDone = $fn->appendBasicBlock('natcasesort_val_cmp_done');
        $this->context->builder->branchIf($isString, $cmpStr, $cmpLong);

        $this->context->builder->positionAtEnd($cmpStr);
        $strCur = $this->context->builder->call(
            $this->context->lookupFunction('__value__readString'),
            $valCur
        );
        $strNext = $this->context->builder->call(
            $this->context->lookupFunction('__value__readString'),
            $valNext
        );
        $cmp = $this->context->builder->call(
            $this->context->lookupFunction('strnatcasecmp'),
            $this->stringDataPtr($strCur),
            $this->stringDataPtr($strNext)
        );
        $this->context->builder->store(
            $this->context->builder->icmp(Builder::INT_SGT, $cmp, $i32->constInt(0, false)),
            $needsSwapSlot
        );
        $this->context->builder->branch($cmpDone);

        $this->context->builder->positionAtEnd($cmpLong);
        $longCur = $this->context->builder->call(
            $this->context->lookupFunction('__value__readLong'),
            $valCur
        );
        $longNext = $this->context->builder->call(
            $this->context->lookupFunction('__value__readLong'),
            $valNext
        );
        $this->context->builder->store(
            $this->context->builder->icmp(Builder::INT_SGT, $longCur, $longNext),
            $needsSwapSlot
        );
        $this->context->builder->branch($cmpDone);

        $this->context->builder->positionAtEnd($cmpDone);
        $needsSwap = $this->context->builder->load($needsSwapSlot);
        $swapBlock = $fn->appendBasicBlock('natcasesort_val_swap');
        $this->context->builder->branchIf($needsSwap, $swapBlock, $advance);

        $this->context->builder->positionAtEnd($swapBlock);
        $prev = $this->context->builder->load($prevSlot);
        $hasPrev = $this->context->builder->icmp(Builder::INT_NE, $prev, $nullNode);
        $updateHead = $fn->appendBasicBlock('natcasesort_val_update_head');
        $updatePrev = $fn->appendBasicBlock('natcasesort_val_update_prev');
        $afterLink = $fn->appendBasicBlock('natcasesort_val_after_link');
        $this->context->builder->branchIf($hasPrev, $updatePrev, $updateHead);

        $this->context->builder->positionAtEnd($updateHead);
        $this->context->builder->store($next, $headSlot);
        $this->context->builder->branch($afterLink);

        $this->context->builder->positionAtEnd($updatePrev);
        $this->context->builder->store(
            $next,
            $this->context->builder->structGep($prev, $nodeMap['next'])
        );
        $this->context->builder->branch($afterLink);

        $this->context->builder->positionAtEnd($afterLink);
        $nextNext = $this->context->builder->load($this->context->builder->structGep($next, $nodeMap['next']));
        $this->context->builder->store($nextNext, $this->context->builder->structGep($cur, $nodeMap['next']));
        $this->context->builder->store($cur, $this->context->builder->structGep($next, $nodeMap['next']));
        $this->context->builder->store($i1->constInt(1, false), $swappedSlot);
        $this->context->builder->store($next, $curSlot);
        $this->context->builder->branch($walkHead);

        $this->context->builder->positionAtEnd($advance);
        $this->context->builder->store($cur, $prevSlot);
        $this->context->builder->store($next, $curSlot);
        $this->context->builder->branch($walkHead);

        $this->context->builder->positionAtEnd($passExit);
        $this->context->builder->branch($passHead);

        $this->context->builder->positionAtEnd($done);
        $this->context->builder->returnVoid();
    }

    private function implementSortStringKeyValuesReverse(): void
    {
        $fn = $this->context->lookupFunction('__hashtable__sortStringKeyValuesReverse');
        $main = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($main);
        $ht = $fn->getParam(0);
        $htMap = $this->context->structFieldMap['__hashtable__'];
        $nodeMap = $this->context->structFieldMap['__strkey_node__'];
        $valueMap = $this->context->structFieldMap['__value__'];
        $headSlot = $this->context->builder->structGep($ht, $htMap['strKeys']);
        $nodePtrType = $this->context->getTypeFromString('__strkey_node__*');
        $nullNode = $nodePtrType->constNull();
        $i1 = $this->context->getTypeFromString('int1');
        $i32 = $this->context->getTypeFromString('int32');
        $i8 = $this->context->getTypeFromString('int8');
        $stringTag = $i8->constInt(Variable::TYPE_STRING, false);

        $head = $this->context->builder->load($headSlot);
        $done = $fn->appendBasicBlock('arsort_val_done');
        $work = $fn->appendBasicBlock('arsort_val_work');
        $headIsNull = $this->context->builder->icmp(Builder::INT_EQ, $head, $nullNode);
        $this->context->builder->branchIf($headIsNull, $done, $work);

        $this->context->builder->positionAtEnd($work);
        $headNext = $this->context->builder->load($this->context->builder->structGep($head, $nodeMap['next']));
        $singleNode = $this->context->builder->icmp(Builder::INT_EQ, $headNext, $nullNode);
        $passStart = $fn->appendBasicBlock('arsort_val_pass');
        $this->context->builder->branchIf($singleNode, $done, $passStart);

        $this->context->builder->positionAtEnd($passStart);
        $swappedSlot = $this->context->builder->alloca($i1, 1, 'arsort_val_swapped');
        $this->context->builder->store($i1->constInt(1, false), $swappedSlot);

        $passHead = $fn->appendBasicBlock('arsort_val_pass_head');
        $passBody = $fn->appendBasicBlock('arsort_val_pass_body');
        $passExit = $fn->appendBasicBlock('arsort_val_pass_exit');
        $this->context->builder->branch($passHead);

        $this->context->builder->positionAtEnd($passHead);
        $didSwap = $this->context->builder->load($swappedSlot);
        $this->context->builder->branchIf($didSwap, $passBody, $done);

        $this->context->builder->positionAtEnd($passBody);
        $this->context->builder->store($i1->constInt(0, false), $swappedSlot);
        $prevSlot = $this->context->builder->alloca($nodePtrType, 1, 'arsort_val_prev');
        $curSlot = $this->context->builder->alloca($nodePtrType, 1, 'arsort_val_cur');
        $needsSwapSlot = $this->context->builder->alloca($i1, 1, 'arsort_val_needs_swap');
        $this->context->builder->store($nullNode, $prevSlot);
        $this->context->builder->store($this->loadStrKeysHead($headSlot), $curSlot);

        $walkHead = $fn->appendBasicBlock('arsort_val_walk_head');
        $walkBody = $fn->appendBasicBlock('arsort_val_walk_body');
        $this->context->builder->branch($walkHead);

        $this->context->builder->positionAtEnd($walkHead);
        $cur = $this->context->builder->load($curSlot);
        $curIsNull = $this->context->builder->icmp(Builder::INT_EQ, $cur, $nullNode);
        $this->context->builder->branchIf($curIsNull, $passExit, $walkBody);

        $this->context->builder->positionAtEnd($walkBody);
        $next = $this->context->builder->load($this->context->builder->structGep($cur, $nodeMap['next']));
        $nextIsNull = $this->context->builder->icmp(Builder::INT_EQ, $next, $nullNode);
        $advance = $fn->appendBasicBlock('arsort_val_advance');
        $compare = $fn->appendBasicBlock('arsort_val_compare');
        $this->context->builder->branchIf($nextIsNull, $passExit, $compare);

        $this->context->builder->positionAtEnd($compare);
        $valCur = $this->context->builder->structGep($cur, $nodeMap['value']);
        $valNext = $this->context->builder->structGep($next, $nodeMap['value']);
        $typeCur = $this->context->builder->load($this->context->builder->structGep($valCur, $valueMap['type']));
        $isString = $this->context->builder->icmp(Builder::INT_EQ, $typeCur, $stringTag);
        $cmpStr = $fn->appendBasicBlock('arsort_val_cmp_str');
        $cmpLong = $fn->appendBasicBlock('arsort_val_cmp_long');
        $cmpDone = $fn->appendBasicBlock('arsort_val_cmp_done');
        $this->context->builder->branchIf($isString, $cmpStr, $cmpLong);

        $this->context->builder->positionAtEnd($cmpStr);
        $strCur = $this->context->builder->call(
            $this->context->lookupFunction('__value__readString'),
            $valCur
        );
        $strNext = $this->context->builder->call(
            $this->context->lookupFunction('__value__readString'),
            $valNext
        );
        $cmp = JitStringCompare::strcmp($this->context, $strCur, $strNext);
        $i64 = $this->context->getTypeFromString('int64');
        $this->context->builder->store(
            $this->context->builder->icmp(Builder::INT_SLT, $cmp, $i64->constInt(0, false)),
            $needsSwapSlot
        );
        $this->context->builder->branch($cmpDone);

        $this->context->builder->positionAtEnd($cmpLong);
        $longCur = $this->context->builder->call(
            $this->context->lookupFunction('__value__readLong'),
            $valCur
        );
        $longNext = $this->context->builder->call(
            $this->context->lookupFunction('__value__readLong'),
            $valNext
        );
        $this->context->builder->store(
            $this->context->builder->icmp(Builder::INT_SLT, $longCur, $longNext),
            $needsSwapSlot
        );
        $this->context->builder->branch($cmpDone);

        $this->context->builder->positionAtEnd($cmpDone);
        $needsSwap = $this->context->builder->load($needsSwapSlot);
        $swapBlock = $fn->appendBasicBlock('arsort_val_swap');
        $this->context->builder->branchIf($needsSwap, $swapBlock, $advance);

        $this->context->builder->positionAtEnd($swapBlock);
        $prev = $this->context->builder->load($prevSlot);
        $hasPrev = $this->context->builder->icmp(Builder::INT_NE, $prev, $nullNode);
        $updateHead = $fn->appendBasicBlock('arsort_val_update_head');
        $updatePrev = $fn->appendBasicBlock('arsort_val_update_prev');
        $afterLink = $fn->appendBasicBlock('arsort_val_after_link');
        $this->context->builder->branchIf($hasPrev, $updatePrev, $updateHead);

        $this->context->builder->positionAtEnd($updateHead);
        $this->context->builder->store($next, $headSlot);
        $this->context->builder->branch($afterLink);

        $this->context->builder->positionAtEnd($updatePrev);
        $this->context->builder->store(
            $next,
            $this->context->builder->structGep($prev, $nodeMap['next'])
        );
        $this->context->builder->branch($afterLink);

        $this->context->builder->positionAtEnd($afterLink);
        $nextNext = $this->context->builder->load($this->context->builder->structGep($next, $nodeMap['next']));
        $this->context->builder->store($nextNext, $this->context->builder->structGep($cur, $nodeMap['next']));
        $this->context->builder->store($cur, $this->context->builder->structGep($next, $nodeMap['next']));
        $this->context->builder->store($i1->constInt(1, false), $swappedSlot);
        $this->context->builder->store($next, $curSlot);
        $this->context->builder->branch($walkHead);

        $this->context->builder->positionAtEnd($advance);
        $this->context->builder->store($cur, $prevSlot);
        $this->context->builder->store($next, $curSlot);
        $this->context->builder->branch($walkHead);

        $this->context->builder->positionAtEnd($passExit);
        $this->context->builder->branch($passHead);

        $this->context->builder->positionAtEnd($done);
        $this->context->builder->returnVoid();
    }


    /**
     * Bubble-sort packed list values in place (sort / rsort SORT_REGULAR) (#24010).
     *
     * NestedJIT {@see \PHPCompiler\ext\standard\SortJitHelper} currently lowers to a no-op
     * stub; this LLVM path matches the asort string-key bubble sort but walks `values[]`.
     */
    private function implementSortPacked(bool $reverse): void
    {
        $abi = $reverse ? '__hashtable__sortPackedReverse' : '__hashtable__sortPacked';
        $tag = $reverse ? 'rsort' : 'sort';
        $fn = $this->context->lookupFunction($abi);
        $main = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($main);
        $ht = $fn->getParam(0);
        $htMap = $this->context->structFieldMap['__hashtable__'];
        $valueMap = $this->context->structFieldMap['__value__'];
        $sizeT = $this->context->getTypeFromString('size_t');
        $i1 = $this->context->getTypeFromString('int1');
        $i32 = $this->context->getTypeFromString('int32');
        $i8 = $this->context->getTypeFromString('int8');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $stringTag = $i8->constInt(Variable::TYPE_STRING, false);
        $valueType = $this->context->getTypeFromString('__value__');

        $n = $this->context->builder->load($this->context->builder->structGep($ht, $htMap['nextFreeElement']));
        $done = $fn->appendBasicBlock($tag.'_done');
        $work = $fn->appendBasicBlock($tag.'_work');
        $tooSmall = $this->context->builder->icmp(
            Builder::INT_ULT,
            $n,
            $sizeT->constInt(2, false)
        );
        $this->context->builder->branchIf($tooSmall, $done, $work);

        $this->context->builder->positionAtEnd($work);
        $swappedSlot = $this->context->builder->alloca($i1, 1, $tag.'_swapped');
        $this->context->builder->store($i1->constInt(1, false), $swappedSlot);
        $passHead = $fn->appendBasicBlock($tag.'_pass_head');
        $passBody = $fn->appendBasicBlock($tag.'_pass_body');
        $this->context->builder->branch($passHead);

        $this->context->builder->positionAtEnd($passHead);
        $didSwap = $this->context->builder->load($swappedSlot);
        $this->context->builder->branchIf($didSwap, $passBody, $done);

        $this->context->builder->positionAtEnd($passBody);
        $this->context->builder->store($i1->constInt(0, false), $swappedSlot);
        $iSlot = $this->context->builder->alloca($sizeT, 1, $tag.'_i');
        $this->context->builder->store($zero, $iSlot);
        $limit = $this->context->builder->sub($n, $one);
        $walkHead = $fn->appendBasicBlock($tag.'_walk_head');
        $walkBody = $fn->appendBasicBlock($tag.'_walk_body');
        $passExit = $fn->appendBasicBlock($tag.'_pass_exit');
        $this->context->builder->branch($walkHead);

        $this->context->builder->positionAtEnd($walkHead);
        $i = $this->context->builder->load($iSlot);
        $atEnd = $this->context->builder->icmp(Builder::INT_UGE, $i, $limit);
        $this->context->builder->branchIf($atEnd, $passExit, $walkBody);

        $this->context->builder->positionAtEnd($walkBody);
        $j = $this->context->builder->addNoSignedWrap($i, $one);
        $valCur = $this->listEntryAt($ht, $htMap, $i);
        $valNext = $this->listEntryAt($ht, $htMap, $j);
        $typeCur = $this->context->builder->load($this->context->builder->structGep($valCur, $valueMap['type']));
        $isString = $this->context->builder->icmp(Builder::INT_EQ, $typeCur, $stringTag);
        $cmpStr = $fn->appendBasicBlock($tag.'_cmp_str');
        $cmpLong = $fn->appendBasicBlock($tag.'_cmp_long');
        $cmpDone = $fn->appendBasicBlock($tag.'_cmp_done');
        $needsSwapSlot = $this->context->builder->alloca($i1, 1, $tag.'_needs_swap');
        $this->context->builder->branchIf($isString, $cmpStr, $cmpLong);

        $this->context->builder->positionAtEnd($cmpStr);
        $strCur = $this->context->builder->call($this->context->lookupFunction('__value__readString'), $valCur);
        $strNext = $this->context->builder->call($this->context->lookupFunction('__value__readString'), $valNext);
        $cmp = JitStringCompare::strcmp($this->context, $strCur, $strNext);
        $i64 = $this->context->getTypeFromString('int64');
        $strOutOfOrder = $reverse
            ? $this->context->builder->icmp(Builder::INT_SLT, $cmp, $i64->constInt(0, false))
            : $this->context->builder->icmp(Builder::INT_SGT, $cmp, $i64->constInt(0, false));
        $this->context->builder->store($strOutOfOrder, $needsSwapSlot);
        $this->context->builder->branch($cmpDone);

        $this->context->builder->positionAtEnd($cmpLong);
        $longCur = $this->context->builder->call($this->context->lookupFunction('__value__readLong'), $valCur);
        $longNext = $this->context->builder->call($this->context->lookupFunction('__value__readLong'), $valNext);
        $longOutOfOrder = $reverse
            ? $this->context->builder->icmp(Builder::INT_SLT, $longCur, $longNext)
            : $this->context->builder->icmp(Builder::INT_SGT, $longCur, $longNext);
        $this->context->builder->store($longOutOfOrder, $needsSwapSlot);
        $this->context->builder->branch($cmpDone);

        $this->context->builder->positionAtEnd($cmpDone);
        $needsSwap = $this->context->builder->load($needsSwapSlot);
        $swapBlock = $fn->appendBasicBlock($tag.'_swap');
        $advance = $fn->appendBasicBlock($tag.'_advance');
        $this->context->builder->branchIf($needsSwap, $swapBlock, $advance);

        $this->context->builder->positionAtEnd($swapBlock);
        $tmp = $this->context->builder->alloca($valueType, 1, $tag.'_tmp');
        $this->context->builder->store($this->context->builder->load($valCur), $tmp);
        $this->context->builder->store($this->context->builder->load($valNext), $valCur);
        $this->context->builder->store($this->context->builder->load($tmp), $valNext);
        $this->context->builder->store($i1->constInt(1, false), $swappedSlot);
        $this->context->builder->branch($advance);

        $this->context->builder->positionAtEnd($advance);
        $this->context->builder->store($this->context->builder->addNoSignedWrap($i, $one), $iSlot);
        $this->context->builder->branch($walkHead);

        $this->context->builder->positionAtEnd($passExit);
        $this->context->builder->branch($passHead);

        $this->context->builder->positionAtEnd($done);
        $this->context->builder->returnVoid();
    }

    /**
     * Bubble-sort packed list values with strnatcmp / strnatcasecmp (#26975).
     *
     * NestedJIT {@see \PHPCompiler\ext\standard\NaturalSortJitHelper} aborts under thin
     * standalone AOT. Mirror {@see implementSortPacked} but compare via the StringNaturalCompare
     * i8* ABI (filled by {@see StringNaturalCompare::ensureStrnatcmpLinked}).
     */
    private function implementSortPackedNatural(bool $caseInsensitive): void
    {
        $abi = $caseInsensitive
            ? '__hashtable__sortPackedNaturalCase'
            : '__hashtable__sortPackedNatural';
        $cmpName = $caseInsensitive ? 'strnatcasecmp' : 'strnatcmp';
        $tag = $caseInsensitive ? 'natcasesort' : 'natsort';
        $fn = $this->context->lookupFunction($abi);
        $main = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($main);
        $ht = $fn->getParam(0);
        $htMap = $this->context->structFieldMap['__hashtable__'];
        $valueMap = $this->context->structFieldMap['__value__'];
        $sizeT = $this->context->getTypeFromString('size_t');
        $i1 = $this->context->getTypeFromString('int1');
        $i32 = $this->context->getTypeFromString('int32');
        $i8 = $this->context->getTypeFromString('int8');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $stringTag = $i8->constInt(Variable::TYPE_STRING, false);
        $valueType = $this->context->getTypeFromString('__value__');

        $n = $this->context->builder->load($this->context->builder->structGep($ht, $htMap['nextFreeElement']));
        $done = $fn->appendBasicBlock($tag.'_done');
        $work = $fn->appendBasicBlock($tag.'_work');
        $tooSmall = $this->context->builder->icmp(
            Builder::INT_ULT,
            $n,
            $sizeT->constInt(2, false)
        );
        $this->context->builder->branchIf($tooSmall, $done, $work);

        $this->context->builder->positionAtEnd($work);
        $swappedSlot = $this->context->builder->alloca($i1, 1, $tag.'_swapped');
        $this->context->builder->store($i1->constInt(1, false), $swappedSlot);
        $passHead = $fn->appendBasicBlock($tag.'_pass_head');
        $passBody = $fn->appendBasicBlock($tag.'_pass_body');
        $this->context->builder->branch($passHead);

        $this->context->builder->positionAtEnd($passHead);
        $didSwap = $this->context->builder->load($swappedSlot);
        $this->context->builder->branchIf($didSwap, $passBody, $done);

        $this->context->builder->positionAtEnd($passBody);
        $this->context->builder->store($i1->constInt(0, false), $swappedSlot);
        $iSlot = $this->context->builder->alloca($sizeT, 1, $tag.'_i');
        $this->context->builder->store($zero, $iSlot);
        $limit = $this->context->builder->sub($n, $one);
        $walkHead = $fn->appendBasicBlock($tag.'_walk_head');
        $walkBody = $fn->appendBasicBlock($tag.'_walk_body');
        $passExit = $fn->appendBasicBlock($tag.'_pass_exit');
        $this->context->builder->branch($walkHead);

        $this->context->builder->positionAtEnd($walkHead);
        $i = $this->context->builder->load($iSlot);
        $atEnd = $this->context->builder->icmp(Builder::INT_UGE, $i, $limit);
        $this->context->builder->branchIf($atEnd, $passExit, $walkBody);

        $this->context->builder->positionAtEnd($walkBody);
        $j = $this->context->builder->addNoSignedWrap($i, $one);
        $valCur = $this->listEntryAt($ht, $htMap, $i);
        $valNext = $this->listEntryAt($ht, $htMap, $j);
        $typeCur = $this->context->builder->load($this->context->builder->structGep($valCur, $valueMap['type']));
        $isString = $this->context->builder->icmp(Builder::INT_EQ, $typeCur, $stringTag);
        $cmpStr = $fn->appendBasicBlock($tag.'_cmp_str');
        $cmpLong = $fn->appendBasicBlock($tag.'_cmp_long');
        $cmpDone = $fn->appendBasicBlock($tag.'_cmp_done');
        $needsSwapSlot = $this->context->builder->alloca($i1, 1, $tag.'_needs_swap');
        $this->context->builder->branchIf($isString, $cmpStr, $cmpLong);

        $this->context->builder->positionAtEnd($cmpStr);
        $strCur = $this->context->builder->call($this->context->lookupFunction('__value__readString'), $valCur);
        $strNext = $this->context->builder->call($this->context->lookupFunction('__value__readString'), $valNext);
        $cmp = $this->context->builder->call(
            $this->context->lookupFunction($cmpName),
            $this->stringDataPtr($strCur),
            $this->stringDataPtr($strNext)
        );
        $this->context->builder->store(
            $this->context->builder->icmp(Builder::INT_SGT, $cmp, $i32->constInt(0, false)),
            $needsSwapSlot
        );
        $this->context->builder->branch($cmpDone);

        $this->context->builder->positionAtEnd($cmpLong);
        $longCur = $this->context->builder->call($this->context->lookupFunction('__value__readLong'), $valCur);
        $longNext = $this->context->builder->call($this->context->lookupFunction('__value__readLong'), $valNext);
        $this->context->builder->store(
            $this->context->builder->icmp(Builder::INT_SGT, $longCur, $longNext),
            $needsSwapSlot
        );
        $this->context->builder->branch($cmpDone);

        $this->context->builder->positionAtEnd($cmpDone);
        $needsSwap = $this->context->builder->load($needsSwapSlot);
        $swapBlock = $fn->appendBasicBlock($tag.'_swap');
        $advance = $fn->appendBasicBlock($tag.'_advance');
        $this->context->builder->branchIf($needsSwap, $swapBlock, $advance);

        $this->context->builder->positionAtEnd($swapBlock);
        $tmp = $this->context->builder->alloca($valueType, 1, $tag.'_tmp');
        $this->context->builder->store($this->context->builder->load($valCur), $tmp);
        $this->context->builder->store($this->context->builder->load($valNext), $valCur);
        $this->context->builder->store($this->context->builder->load($tmp), $valNext);
        $this->context->builder->store($i1->constInt(1, false), $swappedSlot);
        $this->context->builder->branch($advance);

        $this->context->builder->positionAtEnd($advance);
        $this->context->builder->store($this->context->builder->addNoSignedWrap($i, $one), $iSlot);
        $this->context->builder->branch($walkHead);

        $this->context->builder->positionAtEnd($passExit);
        $this->context->builder->branch($passHead);

        $this->context->builder->positionAtEnd($done);
        $this->context->builder->returnVoid();
    }

    /**
     * Coupled bubble-sort for array_multisort() packed hashtable list (#26908).
     *
     * {@see \PHPCompiler\ext\standard\MultisortJitHelper} NestedJIT aborts under thin
     * standalone AOT (HashTable method dispatch / Traversable foreach). Mirror
     * {@see implementSortPacked} and swap every companion when the primary is out of order.
     *
     * @param Value $sources packed list of `__hashtable__*` values (primary first)
     * @param Value $descending int1
     */
    private function implementMultisortPacked(): void
    {
        $fn = $this->context->lookupFunction('__multisort__packed');
        $main = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($main);
        $sources = $fn->getParam(0);
        $descending = $fn->getParam(1);
        $htMap = $this->context->structFieldMap['__hashtable__'];
        $valueMap = $this->context->structFieldMap['__value__'];
        $sizeT = $this->context->getTypeFromString('size_t');
        $i1 = $this->context->getTypeFromString('int1');
        $i8 = $this->context->getTypeFromString('int8');
        $i64 = $this->context->getTypeFromString('int64');
        $valueType = $this->context->getTypeFromString('__value__');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $stringTag = $i8->constInt(Variable::TYPE_STRING, false);
        $tag = 'msort';

        $tableCount = $this->context->builder->load(
            $this->context->builder->structGep($sources, $htMap['nextFreeElement'])
        );
        $done = $fn->appendBasicBlock($tag.'_done');
        $loadPrimary = $fn->appendBasicBlock($tag.'_load_primary');
        $tooFewTables = $this->context->builder->icmp(
            Builder::INT_ULT,
            $tableCount,
            $sizeT->constInt(2, false)
        );
        $this->context->builder->branchIf($tooFewTables, $done, $loadPrimary);

        $this->context->builder->positionAtEnd($loadPrimary);
        $primaryVal = $this->listEntryAt($sources, $htMap, $zero);
        $primary = $this->context->builder->call(
            $this->context->lookupFunction('__value__readHashtable'),
            $primaryVal
        );
        $length = $this->context->builder->load(
            $this->context->builder->structGep($primary, $htMap['nextFreeElement'])
        );
        $work = $fn->appendBasicBlock($tag.'_work');
        $tooShort = $this->context->builder->icmp(
            Builder::INT_ULT,
            $length,
            $sizeT->constInt(2, false)
        );
        $this->context->builder->branchIf($tooShort, $done, $work);

        $this->context->builder->positionAtEnd($work);
        $firstVal = $this->listEntryAt($primary, $htMap, $zero);
        $firstType = $this->context->builder->load(
            $this->context->builder->structGep($firstVal, $valueMap['type'])
        );
        $isString = $this->context->builder->icmp(Builder::INT_EQ, $firstType, $stringTag);
        $isDesc = $this->context->builder->icmp(
            Builder::INT_NE,
            $descending,
            $i1->constInt(0, false)
        );

        $outerSlot = $this->context->builder->alloca($sizeT, 1, $tag.'_outer');
        $this->context->builder->store($zero, $outerSlot);
        $outerHead = $fn->appendBasicBlock($tag.'_outer_head');
        $outerBody = $fn->appendBasicBlock($tag.'_outer_body');
        $this->context->builder->branch($outerHead);

        $this->context->builder->positionAtEnd($outerHead);
        $outer = $this->context->builder->load($outerSlot);
        $outerLimit = $this->context->builder->sub($length, $one);
        $outerDone = $this->context->builder->icmp(Builder::INT_UGE, $outer, $outerLimit);
        $this->context->builder->branchIf($outerDone, $done, $outerBody);

        $this->context->builder->positionAtEnd($outerBody);
        $innerSlot = $this->context->builder->alloca($sizeT, 1, $tag.'_inner');
        $this->context->builder->store($zero, $innerSlot);
        $innerLimit = $this->context->builder->sub($outerLimit, $outer);
        $innerHead = $fn->appendBasicBlock($tag.'_inner_head');
        $innerBody = $fn->appendBasicBlock($tag.'_inner_body');
        $outerAdvance = $fn->appendBasicBlock($tag.'_outer_adv');
        $this->context->builder->branch($innerHead);

        $this->context->builder->positionAtEnd($innerHead);
        $inner = $this->context->builder->load($innerSlot);
        $innerDone = $this->context->builder->icmp(Builder::INT_UGE, $inner, $innerLimit);
        $this->context->builder->branchIf($innerDone, $outerAdvance, $innerBody);

        $this->context->builder->positionAtEnd($innerBody);
        $innerNext = $this->context->builder->addNoSignedWrap($inner, $one);
        $valCur = $this->listEntryAt($primary, $htMap, $inner);
        $valNext = $this->listEntryAt($primary, $htMap, $innerNext);
        $cmpStr = $fn->appendBasicBlock($tag.'_cmp_str');
        $cmpLong = $fn->appendBasicBlock($tag.'_cmp_long');
        $cmpDone = $fn->appendBasicBlock($tag.'_cmp_done');
        $needsSwapSlot = $this->context->builder->alloca($i1, 1, $tag.'_needs_swap');
        $this->context->builder->branchIf($isString, $cmpStr, $cmpLong);

        $this->context->builder->positionAtEnd($cmpStr);
        $strCur = $this->context->builder->call($this->context->lookupFunction('__value__readString'), $valCur);
        $strNext = $this->context->builder->call($this->context->lookupFunction('__value__readString'), $valNext);
        $cmp = JitStringCompare::strcmp($this->context, $strCur, $strNext);
        $strGt = $this->context->builder->icmp(Builder::INT_SGT, $cmp, $i64->constInt(0, false));
        $strLt = $this->context->builder->icmp(Builder::INT_SLT, $cmp, $i64->constInt(0, false));
        $strOutOfOrder = $this->context->builder->select($isDesc, $strLt, $strGt);
        $this->context->builder->store($strOutOfOrder, $needsSwapSlot);
        $this->context->builder->branch($cmpDone);

        $this->context->builder->positionAtEnd($cmpLong);
        $longCur = $this->context->builder->call($this->context->lookupFunction('__value__readLong'), $valCur);
        $longNext = $this->context->builder->call($this->context->lookupFunction('__value__readLong'), $valNext);
        $longGt = $this->context->builder->icmp(Builder::INT_SGT, $longCur, $longNext);
        $longLt = $this->context->builder->icmp(Builder::INT_SLT, $longCur, $longNext);
        $longOutOfOrder = $this->context->builder->select($isDesc, $longLt, $longGt);
        $this->context->builder->store($longOutOfOrder, $needsSwapSlot);
        $this->context->builder->branch($cmpDone);

        $this->context->builder->positionAtEnd($cmpDone);
        $needsSwap = $this->context->builder->load($needsSwapSlot);
        $swapAll = $fn->appendBasicBlock($tag.'_swap_all');
        $innerAdvance = $fn->appendBasicBlock($tag.'_inner_adv');
        $this->context->builder->branchIf($needsSwap, $swapAll, $innerAdvance);

        $this->context->builder->positionAtEnd($swapAll);
        $tSlot = $this->context->builder->alloca($sizeT, 1, $tag.'_t');
        $this->context->builder->store($zero, $tSlot);
        $swapHead = $fn->appendBasicBlock($tag.'_swap_head');
        $swapBody = $fn->appendBasicBlock($tag.'_swap_body');
        $this->context->builder->branch($swapHead);

        $this->context->builder->positionAtEnd($swapHead);
        $t = $this->context->builder->load($tSlot);
        $swapDone = $this->context->builder->icmp(Builder::INT_UGE, $t, $tableCount);
        $this->context->builder->branchIf($swapDone, $innerAdvance, $swapBody);

        $this->context->builder->positionAtEnd($swapBody);
        $tableVal = $this->listEntryAt($sources, $htMap, $t);
        $table = $this->context->builder->call(
            $this->context->lookupFunction('__value__readHashtable'),
            $tableVal
        );
        $a = $this->listEntryAt($table, $htMap, $inner);
        $b = $this->listEntryAt($table, $htMap, $innerNext);
        $tmp = $this->context->builder->alloca($valueType, 1, $tag.'_tmp');
        $this->context->builder->store($this->context->builder->load($a), $tmp);
        $this->context->builder->store($this->context->builder->load($b), $a);
        $this->context->builder->store($this->context->builder->load($tmp), $b);
        $this->context->builder->store($this->context->builder->addNoSignedWrap($t, $one), $tSlot);
        $this->context->builder->branch($swapHead);

        $this->context->builder->positionAtEnd($innerAdvance);
        $this->context->builder->store($this->context->builder->addNoSignedWrap($inner, $one), $innerSlot);
        $this->context->builder->branch($innerHead);

        $this->context->builder->positionAtEnd($outerAdvance);
        $this->context->builder->store($this->context->builder->addNoSignedWrap($outer, $one), $outerSlot);
        $this->context->builder->branch($outerHead);

        $this->context->builder->positionAtEnd($done);
        $this->context->builder->returnVoid();
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
    /** Reload strKeys list head after earlier inserts in the same LLVM function. */
    private function loadStrKeysHead(PHPLLVM\Value $headSlot): PHPLLVM\Value
    {
        return $this->context->builder->load($headSlot);
    }

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

    private function decrementNumElements(PHPLLVM\Value $ht): void
    {
        $map = $this->context->structFieldMap['__hashtable__'];
        $sizeT = $this->context->getTypeFromString('size_t');
        $numPtr = $this->context->builder->structGep($ht, $map['numElements']);
        $num = $this->context->builder->load($numPtr);
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $newNum = $this->context->builder->sub($num, $one);
        $clamped = $this->context->builder->select(
            $this->context->builder->icmp(Builder::INT_EQ, $num, $zero),
            $zero,
            $newNum
        );
        $this->context->builder->store($clamped, $numPtr);
    }

    private function implementUnsetLongAt(): void
    {
        $fn = $this->context->lookupFunction('__hashtable__unsetLongAt');
        $block = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($block);
        $ht = $fn->getParam(0);
        $index = $fn->getParam(1);
        $wasSet = $this->context->builder->call(
            $this->context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $index
        );
        $unsetBlock = $fn->appendBasicBlock('unset_long_do');
        $done = $fn->appendBasicBlock('unset_long_done');
        $this->context->builder->branchIf($wasSet, $unsetBlock, $done);
        $this->context->builder->positionAtEnd($unsetBlock);
        $map = $this->context->structFieldMap['__hashtable__'];
        $values = $this->context->builder->load($this->context->builder->structGep($ht, $map['values']));
        $entry = $this->context->builder->inBoundsGep($values, $index);
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeNull'),
            $entry
        );
        $this->decrementNumElements($ht);
        // Trailing packed unset: shrink nextFreeElement past any trailing holes so a later
        // write at the same index bumps numElements (Zend packed del / #28051).
        $sizeT = $this->context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nextFreePtr = $this->context->builder->structGep($ht, $map['nextFreeElement']);
        $shrinkHead = $fn->appendBasicBlock('unset_long_shrink_head');
        $shrinkBody = $fn->appendBasicBlock('unset_long_shrink_body');
        $afterShrink = $fn->appendBasicBlock('unset_long_after_shrink');
        $this->context->builder->branch($shrinkHead);
        $this->context->builder->positionAtEnd($shrinkHead);
        $nextFree = $this->context->builder->load($nextFreePtr);
        $canShrink = $this->context->builder->icmp(Builder::INT_UGT, $nextFree, $zero);
        $this->context->builder->branchIf($canShrink, $shrinkBody, $afterShrink);
        $this->context->builder->positionAtEnd($shrinkBody);
        $lastIdx = $this->context->builder->sub($nextFree, $one);
        $lastSet = $this->context->builder->call(
            $this->context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $lastIdx
        );
        $doShrink = $fn->appendBasicBlock('unset_long_do_shrink');
        $this->context->builder->branchIf($lastSet, $afterShrink, $doShrink);
        $this->context->builder->positionAtEnd($doShrink);
        $this->context->builder->store($lastIdx, $nextFreePtr);
        $this->context->builder->branch($shrinkHead);
        $this->context->builder->positionAtEnd($afterShrink);
        $this->context->builder->branch($done);
        $this->context->builder->positionAtEnd($done);
        $this->context->builder->returnVoid();
    }

    private function implementUnsetStringKey(): void
    {
        $fn = $this->context->lookupFunction('__hashtable__unsetStringKey');
        $block = $fn->appendBasicBlock('main');
        $this->context->builder->positionAtEnd($block);
        $ht = $fn->getParam(0);
        $key = $fn->getParam(1);

        $htMap = $this->context->structFieldMap['__hashtable__'];
        $nodeMap = $this->context->structFieldMap['__strkey_node__'];
        $headSlot = $this->context->builder->structGep($ht, $htMap['strKeys']);
        $nodePtrType = $this->context->getTypeFromString('__strkey_node__*');

        $prevSlot = $this->context->builder->alloca($nodePtrType, 1, 'strkey_unset_prev');
        $this->context->builder->store($nodePtrType->constNull(), $prevSlot);
        $currentSlot = $this->context->builder->alloca($nodePtrType, 1, 'strkey_unset_current');
        $this->context->builder->store($this->context->builder->load($headSlot), $currentSlot);

        $done = $fn->appendBasicBlock('strkey_unset_done');
        $loopHead = $fn->appendBasicBlock('strkey_unset_head');
        $loopBody = $fn->appendBasicBlock('strkey_unset_body');
        $this->context->builder->branch($loopHead);

        $this->context->builder->positionAtEnd($loopHead);
        $node = $this->context->builder->load($currentSlot);
        $isNull = $this->context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $this->context->builder->branchIf($isNull, $done, $loopBody);

        $this->context->builder->positionAtEnd($loopBody);
        $nodeKey = $this->context->builder->load($this->context->builder->structGep($node, $nodeMap['key']));
        $isMatch = JitStringCompare::identical($this->context, $key, $nodeKey);
        $remove = $fn->appendBasicBlock('strkey_unset_remove');
        $next = $fn->appendBasicBlock('strkey_unset_next');
        $this->context->builder->branchIf($isMatch, $remove, $next);

        $this->context->builder->positionAtEnd($remove);
        $nextNode = $this->context->builder->load($this->context->builder->structGep($node, $nodeMap['next']));
        $prev = $this->context->builder->load($prevSlot);
        $hasPrev = $this->context->builder->icmp(Builder::INT_NE, $prev, $nodePtrType->constNull());
        $updateHead = $fn->appendBasicBlock('strkey_unset_update_head');
        $updatePrev = $fn->appendBasicBlock('strkey_unset_update_prev');
        $afterUnlink = $fn->appendBasicBlock('strkey_unset_after_unlink');
        $this->context->builder->branchIf($hasPrev, $updatePrev, $updateHead);

        $this->context->builder->positionAtEnd($updateHead);
        $this->context->builder->store($nextNode, $headSlot);
        $this->context->builder->branch($afterUnlink);

        $this->context->builder->positionAtEnd($updatePrev);
        $this->context->builder->store(
            $nextNode,
            $this->context->builder->structGep($prev, $nodeMap['next'])
        );
        $this->context->builder->branch($afterUnlink);

        $this->context->builder->positionAtEnd($afterUnlink);
        $valField = $this->context->builder->structGep($node, $nodeMap['value']);
        $this->context->builder->call($this->context->lookupFunction('__value__writeNull'), $valField);
        $this->decrementNumElements($ht);
        $this->context->builder->branch($done);

        $this->context->builder->positionAtEnd($next);
        $this->context->builder->store($node, $prevSlot);
        $nextNode = $this->context->builder->load($this->context->builder->structGep($node, $nodeMap['next']));
        $this->context->builder->store($nextNode, $currentSlot);
        $this->context->builder->branch($loopHead);

        $this->context->builder->positionAtEnd($done);
        $this->context->builder->returnVoid();
    }

    /**
     * Fresh strkey/objkey nodes must be zeroed before writeString/writeNull.
     * Those helpers always valueDelref first; a non-zero garbage type byte
     * frees an invalid pointer (munmap_chunk) — environ-sensitive (#19627).
     */
    private function mallocZeroedNode(PHPLLVM\Type $nodeType): PHPLLVM\Value
    {
        $newNode = $this->context->memory->malloc($nodeType);
        $i8p = $this->context->getTypeFromString('int8*');
        $zeroI8 = $this->context->getTypeFromString('int8')->constInt(0, false);
        $size = $this->context->builder->ptrToInt(
            $this->context->builder->gep(
                $nodeType->pointerType(0)->constNull(),
                $this->context->context->int32Type()->constInt(1, false)
            ),
            $this->context->getTypeFromString('size_t')
        );
        $this->context->intrinsic->memset(
            $this->context->builder->pointerCast($newNode, $i8p),
            $zeroI8,
            $size,
            false
        );

        return $newNode;
    }

    private function updateIndexMetadata(
        PHPLLVM\Value $ht,
        array $map,
        PHPLLVM\Value $index,
        PHPLLVM\Value $need,
        PHPLLVM\Value $wasSetBeforeWrite,
        bool $countNewElements = true
    ): void {
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
        if ($countNewElements) {
            $sizeT = $need->typeOf();
            $one = $sizeT->constInt(1, false);
            $incr = $this->context->builder->zExt(
                $this->context->builder->not($wasSetBeforeWrite),
                $sizeT
            );
            $newNum = $this->context->builder->addNoSignedWrap($numElements, $incr);
        } else {
            $updateNum = $this->context->builder->icmp(Builder::INT_UGE, $index, $numElements);
            $newNum = $this->context->builder->select($updateNum, $need, $numElements);
        }
        $this->context->builder->store(
            $newNum,
            $this->context->builder->structGep($ht, $map['numElements'])
        );
    }

}
