<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Call-site LLVM for array_column() string-key array-of-arrays path (#26955, #27131).
 *
 * Thin AOT NestedJIT of ArrayColumnJitHelper fatals on EnumCaseEntry::fetchProperty
 * / ObjectEntry::hasProperty (mis-resolved onto the helper) and aborts on
 * HashTable::iterate (peer ArrayFlip #26970 / #21981). Walk packed + string-key
 * entries with HashTableHelper APIs instead.
 *
 * After appends, sync {@see numElements}/{@see nextFreeElement} like
 * {@see HashTableWriteLlvm::materializeNativeArrayForCall()} / {@see HashTableReverseLlvm}
 * so NestedJIT {@see isPackedList}/{@see exportKeyValuePairs} agree with index access —
 * otherwise thin AOT {@see json_encode} prints `{}` (#27131; peer #27130).
 *
 * VM SSOT remains {@see \PHPCompiler\ext\standard\ArrayColumnJitHelper}.
 * php-src: ext/standard/array.c — php_array_column()
 */
final class ArrayColumnLlvm
{
    private static int $seq = 0;

    /** Extract column $keyStr from each array row; append to a new hashtable. */
    public static function columnWithStringKey(Context $context, Value $src, Value $keyStr): Value
    {
        $dest = HashTableHelper::alloc($context);
        self::walkPackedRows($context, $src, $dest, $keyStr);
        self::walkStringKeyRows($context, $src, $dest, $keyStr);
        self::syncPackedListMetadata($context, $dest);

        return $dest;
    }

    /**
     * Dense packed-list metadata for NestedJIT json_encode (#27131 / peer #27130).
     * Do not rely solely on setAtIndex nextFree updates.
     */
    private static function syncPackedListMetadata(Context $context, Value $dest): void
    {
        $htMap = $context->structFieldMap['__hashtable__'];
        $nextFree = $context->builder->load(
            $context->builder->structGep($dest, $htMap['nextFreeElement'])
        );
        $context->builder->store(
            $nextFree,
            $context->builder->structGep($dest, $htMap['numElements'])
        );
        $context->builder->store(
            $nextFree,
            $context->builder->structGep($dest, $htMap['nextFreeElement'])
        );
    }

    private static function walkPackedRows(
        Context $context,
        Value $src,
        Value $dest,
        Value $keyStr
    ): void {
        $tag = (string) (++self::$seq);
        $htMap = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $nextFree = $context->builder->load($context->builder->structGep($src, $htMap['nextFreeElement']));
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'array_column_pk_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'array_column_pk_body_'.$tag);
        $take = BasicBlockHelper::append($context, 'array_column_pk_take_'.$tag);
        $next = BasicBlockHelper::append($context, 'array_column_pk_next_'.$tag);
        $done = BasicBlockHelper::append($context, 'array_column_pk_done_'.$tag);
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
        $context->builder->branchIf($isSet, $take, $next);

        $context->builder->positionAtEnd($take);
        $rowVar = HashTableReadLlvm::readIndexedToValueBox($context, $src, $idx);
        self::appendColumnFromRow($context, $dest, $rowVar, $keyStr);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function walkStringKeyRows(
        Context $context,
        Value $src,
        Value $dest,
        Value $keyStr
    ): void {
        $tag = (string) (++self::$seq);
        $htMap = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrTy = $context->getTypeFromString('__strkey_node__*');

        $nodeSlot = BasicBlockHelper::entryAlloca($context, $nodePtrTy);
        $headNode = $context->builder->load($context->builder->structGep($src, $htMap['strKeys']));
        $context->builder->store($headNode, $nodeSlot);

        $head = BasicBlockHelper::append($context, 'array_column_sk_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'array_column_sk_body_'.$tag);
        $next = BasicBlockHelper::append($context, 'array_column_sk_next_'.$tag);
        $done = BasicBlockHelper::append($context, 'array_column_sk_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $node = $context->builder->load($nodeSlot);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrTy->constNull());
        $context->builder->branchIf($isNull, $done, $body);

        $context->builder->positionAtEnd($body);
        $valField = $context->builder->structGep($node, $nodeMap['value']);
        $valSlot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer($context, $valSlot, $valField);
        $rowVar = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $valSlot);
        self::appendColumnFromRow($context, $dest, $rowVar, $keyStr);

        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $nodeSlot);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    /**
     * If $rowVar is an array hashtable, read $keyStr and append when present.
     * Object/enum rows are skipped here (VM ArrayColumnJitHelper covers those).
     */
    private static function appendColumnFromRow(
        Context $context,
        Value $dest,
        Variable $rowVar,
        Value $keyStr
    ): void {
        $tag = (string) (++self::$seq);
        $i8 = $context->getTypeFromString('int8');
        $rowPtr = JitValueBox::valuePtrFromVariable($context, $rowVar);
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load($context->builder->structGep($rowPtr, $valueMap['type']));

        $arrBb = BasicBlockHelper::append($context, 'array_column_row_arr_'.$tag);
        $done = BasicBlockHelper::append($context, 'array_column_row_done_'.$tag);
        // Value-box array rows use VM TYPE_ARRAY (6); some writers store JIT TYPE_HASHTABLE.
        $isVmArray = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_ARRAY, false)
        );
        $isJitHt = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_HASHTABLE, false)
        );
        $isArray = $context->builder->or($isVmArray, $isJitHt);
        $context->builder->branchIf($isArray, $arrBb, $done);

        $context->builder->positionAtEnd($arrBb);
        $rowHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $rowPtr
        );
        $cellPtr = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringKeyValue'),
            $rowHt,
            $keyStr
        );
        $nullBb = BasicBlockHelper::append($context, 'array_column_cell_null_'.$tag);
        $copyBb = BasicBlockHelper::append($context, 'array_column_cell_copy_'.$tag);
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $cellPtr,
            $cellPtr->typeOf()->constNull()
        );
        $context->builder->branchIf($isNull, $nullBb, $copyBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($copyBb);
        $cellSlot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer($context, $cellSlot, $cellPtr);
        $cellVar = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $cellSlot);
        $cellPtr2 = JitValueBox::valuePtrFromVariable($context, $cellVar);
        $cellType = $context->builder->load($context->builder->structGep($cellPtr2, $valueMap['type']));
        $undefBb = BasicBlockHelper::append($context, 'array_column_cell_undef_'.$tag);
        $appendBb = BasicBlockHelper::append($context, 'array_column_cell_append_'.$tag);
        $isUndef = $context->builder->icmp(
            Builder::INT_EQ,
            $cellType,
            $i8->constInt(VmVariable::TYPE_UNDEFINED, false)
        );
        $context->builder->branchIf($isUndef, $undefBb, $appendBb);

        $context->builder->positionAtEnd($undefBb);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($appendBb);
        $htMap = $context->structFieldMap['__hashtable__'];
        $index = $context->builder->load($context->builder->structGep($dest, $htMap['nextFreeElement']));
        HashTableHelper::setAtIndex($context, $dest, $index, $cellVar);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }
}
