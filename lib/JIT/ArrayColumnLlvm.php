<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\Type\Object_ as JitObjectType;
use PHPCompiler\VM\EnumCasePropertyJitHelper;
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
     * Extract $columnKeyStr from each row; store at $indexKeyStr cell (php-src php_array_column).
     * Associative result — do not sync packed-list metadata (#13970 / maintainer_gap_array_column_index_key).
     */
    public static function columnWithStringKeyAndIndex(
        Context $context,
        Value $src,
        Value $columnKeyStr,
        Value $indexKeyStr
    ): Value {
        $dest = HashTableHelper::alloc($context);
        self::walkPackedRowsWithIndex($context, $src, $dest, $columnKeyStr, $indexKeyStr);
        self::walkStringKeyRowsWithIndex($context, $src, $dest, $columnKeyStr, $indexKeyStr);

        return $dest;
    }

    /** null column_key: store each row at its $indexKeyStr cell (#15914 / array_column null + index_key). */
    public static function columnNullWithStringIndex(Context $context, Value $src, Value $indexKeyStr): Value
    {
        $dest = HashTableHelper::alloc($context);
        self::walkPackedRowsNullColumnWithIndex($context, $src, $dest, $indexKeyStr);
        self::walkStringKeyRowsNullColumnWithIndex($context, $src, $dest, $indexKeyStr);

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

    private static function walkPackedRowsWithIndex(
        Context $context,
        Value $src,
        Value $dest,
        Value $columnKeyStr,
        Value $indexKeyStr
    ): void {
        $tag = (string) (++self::$seq);
        $htMap = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $nextFree = $context->builder->load($context->builder->structGep($src, $htMap['nextFreeElement']));
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'array_column_pkix_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'array_column_pkix_body_'.$tag);
        $take = BasicBlockHelper::append($context, 'array_column_pkix_take_'.$tag);
        $next = BasicBlockHelper::append($context, 'array_column_pkix_next_'.$tag);
        $done = BasicBlockHelper::append($context, 'array_column_pkix_done_'.$tag);
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
        self::storeColumnFromRowWithIndex($context, $dest, $rowVar, $columnKeyStr, $indexKeyStr);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function walkStringKeyRowsWithIndex(
        Context $context,
        Value $src,
        Value $dest,
        Value $columnKeyStr,
        Value $indexKeyStr
    ): void {
        $tag = (string) (++self::$seq);
        $htMap = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrTy = $context->getTypeFromString('__strkey_node__*');

        $nodeSlot = BasicBlockHelper::entryAlloca($context, $nodePtrTy);
        $headNode = $context->builder->load($context->builder->structGep($src, $htMap['strKeys']));
        $context->builder->store($headNode, $nodeSlot);

        $head = BasicBlockHelper::append($context, 'array_column_skix_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'array_column_skix_body_'.$tag);
        $next = BasicBlockHelper::append($context, 'array_column_skix_next_'.$tag);
        $done = BasicBlockHelper::append($context, 'array_column_skix_done_'.$tag);
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
        self::storeColumnFromRowWithIndex($context, $dest, $rowVar, $columnKeyStr, $indexKeyStr);

        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $nodeSlot);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function walkPackedRowsNullColumnWithIndex(
        Context $context,
        Value $src,
        Value $dest,
        Value $indexKeyStr
    ): void {
        $tag = (string) (++self::$seq);
        $htMap = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $nextFree = $context->builder->load($context->builder->structGep($src, $htMap['nextFreeElement']));
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'array_column_pknullix_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'array_column_pknullix_body_'.$tag);
        $take = BasicBlockHelper::append($context, 'array_column_pknullix_take_'.$tag);
        $next = BasicBlockHelper::append($context, 'array_column_pknullix_next_'.$tag);
        $done = BasicBlockHelper::append($context, 'array_column_pknullix_done_'.$tag);
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
        self::storeRowAtIndexKeyFromRow($context, $dest, $rowVar, $indexKeyStr);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function walkStringKeyRowsNullColumnWithIndex(
        Context $context,
        Value $src,
        Value $dest,
        Value $indexKeyStr
    ): void {
        $tag = (string) (++self::$seq);
        $htMap = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrTy = $context->getTypeFromString('__strkey_node__*');

        $nodeSlot = BasicBlockHelper::entryAlloca($context, $nodePtrTy);
        $headNode = $context->builder->load($context->builder->structGep($src, $htMap['strKeys']));
        $context->builder->store($headNode, $nodeSlot);

        $head = BasicBlockHelper::append($context, 'array_column_sknullix_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'array_column_sknullix_body_'.$tag);
        $next = BasicBlockHelper::append($context, 'array_column_sknullix_next_'.$tag);
        $done = BasicBlockHelper::append($context, 'array_column_sknullix_done_'.$tag);
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
        self::storeRowAtIndexKeyFromRow($context, $dest, $rowVar, $indexKeyStr);

        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $nodeSlot);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    /**
     * If $rowVar is an array hashtable, read $keyStr and append when present.
     * Enum case rows read name/value pseudo-properties (php-src array.c; #maintainer_gap_array_column_enum_cases_name).
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
        $enumBb = BasicBlockHelper::append($context, 'array_column_row_enum_'.$tag);
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
        $context->builder->branchIf($isArray, $arrBb, $enumBb);

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

        $context->builder->positionAtEnd($enumBb);
        self::tryAppendEnumCaseColumn($context, $dest, $rowPtr, $typeByte, $keyStr, $done, $tag);

        $context->builder->positionAtEnd($done);
    }

    /** Store $columnKeyStr cell at $indexKeyStr cell when both exist on an array row. */
    private static function storeColumnFromRowWithIndex(
        Context $context,
        Value $dest,
        Variable $rowVar,
        Value $columnKeyStr,
        Value $indexKeyStr
    ): void {
        $tag = (string) (++self::$seq);
        $done = BasicBlockHelper::append($context, 'array_column_store_ix_done_'.$tag);
        $haveIndex = BasicBlockHelper::append($context, 'array_column_store_ix_have_index_'.$tag);
        $haveColumn = BasicBlockHelper::append($context, 'array_column_store_ix_have_column_'.$tag);

        $indexSlot = JitValueBox::alloc($context);
        self::emitReadStringKeyCellFromRow(
            $context,
            $rowVar,
            $indexKeyStr,
            $indexSlot,
            $done,
            $haveIndex,
            $tag.'_ix'
        );

        $context->builder->positionAtEnd($haveIndex);
        $columnSlot = JitValueBox::alloc($context);
        self::emitReadStringKeyCellFromRow(
            $context,
            $rowVar,
            $columnKeyStr,
            $columnSlot,
            $done,
            $haveColumn,
            $tag.'_col'
        );

        $context->builder->positionAtEnd($haveColumn);
        $indexVar = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $indexSlot);
        $columnVar = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $columnSlot);
        HashTableHelper::setValueBoxKey($context, $dest, $indexVar, $columnVar);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    /** null column_key: store the whole $rowVar at its $indexKeyStr cell. */
    private static function storeRowAtIndexKeyFromRow(
        Context $context,
        Value $dest,
        Variable $rowVar,
        Value $indexKeyStr
    ): void {
        $tag = (string) (++self::$seq);
        $done = BasicBlockHelper::append($context, 'array_column_store_row_ix_done_'.$tag);
        $haveIndex = BasicBlockHelper::append($context, 'array_column_store_row_ix_have_'.$tag);

        $indexSlot = JitValueBox::alloc($context);
        self::emitReadStringKeyCellFromRow(
            $context,
            $rowVar,
            $indexKeyStr,
            $indexSlot,
            $done,
            $haveIndex,
            $tag
        );

        $context->builder->positionAtEnd($haveIndex);
        $indexVar = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $indexSlot);
        HashTableHelper::setValueBoxKey($context, $dest, $indexVar, $rowVar);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    /**
     * Read a string-key cell from an array $rowVar into $destSlot.
     * On success continue at $ok; on skip branch to $skip.
     */
    private static function emitReadStringKeyCellFromRow(
        Context $context,
        Variable $rowVar,
        Value $keyStr,
        Value $destSlot,
        $skip,
        $ok,
        string $tag
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $rowPtr = JitValueBox::valuePtrFromVariable($context, $rowVar);
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load($context->builder->structGep($rowPtr, $valueMap['type']));

        $arrBb = BasicBlockHelper::append($context, 'array_column_read_arr_'.$tag);
        $notArrBb = BasicBlockHelper::append($context, 'array_column_read_not_arr_'.$tag);
        $nullBb = BasicBlockHelper::append($context, 'array_column_read_null_'.$tag);
        $undefBb = BasicBlockHelper::append($context, 'array_column_read_undef_'.$tag);
        $copyBb = BasicBlockHelper::append($context, 'array_column_read_copy_'.$tag);

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
        $context->builder->branchIf($isArray, $arrBb, $notArrBb);

        $context->builder->positionAtEnd($notArrBb);
        $context->builder->branch($skip);

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
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $cellPtr,
            $cellPtr->typeOf()->constNull()
        );
        $context->builder->branchIf($isNull, $nullBb, $copyBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->branch($skip);

        $context->builder->positionAtEnd($copyBb);
        JitValueBox::copyFromPointer($context, $destSlot, $cellPtr);
        $cellVar = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $destSlot);
        $cellPtr2 = JitValueBox::valuePtrFromVariable($context, $cellVar);
        $cellType = $context->builder->load($context->builder->structGep($cellPtr2, $valueMap['type']));
        $isUndef = $context->builder->icmp(
            Builder::INT_EQ,
            $cellType,
            $i8->constInt(VmVariable::TYPE_UNDEFINED, false)
        );
        $context->builder->branchIf($isUndef, $undefBb, $ok);

        $context->builder->positionAtEnd($undefBb);
        $context->builder->branch($skip);
    }

    /**
     * Enum case rows: extract ->name / ->value when $keyStr matches (php-src php_array_column).
     */
    private static function tryAppendEnumCaseColumn(
        Context $context,
        Value $dest,
        Value $rowPtr,
        Value $typeByte,
        Value $keyStr,
        $done,
        string $tag
    ): void {
        $objectType = $context->type->object;
        if (!$objectType instanceof JitObjectType) {
            $context->builder->branch($done);

            return;
        }
        $enumIds = $objectType->registeredEnumClassIds();
        if ([] === $enumIds) {
            $context->builder->branch($done);

            return;
        }

        $i8 = $context->getTypeFromString('int8');
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_OBJECT & 0x7f, false)
        );
        $isEnumCase = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(VmVariable::TYPE_ENUM_CASE & 0x7f, false)
        );
        $isObjectLike = $context->builder->or($isObject, $isEnumCase);

        $objBb = BasicBlockHelper::append($context, 'array_column_enum_obj_'.$tag);
        $context->builder->branchIf($isObjectLike, $objBb, $done);

        $context->builder->positionAtEnd($objBb);
        $objPtr = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $rowPtr
        );

        $nameKey = $context->builder->load($context->constantStringFromString('name'));
        $valueKey = $context->builder->load($context->constantStringFromString('value'));
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $isNameKey = $context->builder->icmp(
            Builder::INT_EQ,
            JitStringCompare::strcmp($context, $keyStr, $nameKey),
            $zero
        );
        $isValueKey = $context->builder->icmp(
            Builder::INT_EQ,
            JitStringCompare::strcmp($context, $keyStr, $valueKey),
            $zero
        );
        $isEnumProp = $context->builder->or($isNameKey, $isValueKey);

        $propBb = BasicBlockHelper::append($context, 'array_column_enum_prop_'.$tag);
        $context->builder->branchIf($isEnumProp, $propBb, $done);

        $context->builder->positionAtEnd($propBb);
        self::appendEnumCasePropertyWhenClassMatches(
            $context,
            $objectType,
            $dest,
            $objPtr,
            $isNameKey,
            $enumIds,
            $done,
            $tag
        );
    }

    /**
     * @param list<int> $enumIds
     */
    private static function appendEnumCasePropertyWhenClassMatches(
        Context $context,
        JitObjectType $objectType,
        Value $dest,
        Value $objPtr,
        Value $isNameKey,
        array $enumIds,
        $done,
        string $tag
    ): void {
        $objMap = $context->structFieldMap['__object__'];
        $runtimeClassId = $context->builder->load(
            $context->builder->structGep($objPtr, $objMap['class_id'])
        );
        $fn = BasicBlockHelper::parentFunction($context);
        $i64 = $context->getTypeFromString('int64');
        $checkBlock = $context->builder->getInsertBlock();
        $lastIdx = \count($enumIds) - 1;
        foreach ($enumIds as $idx => $enumId) {
            $context->builder->positionAtEnd($checkBlock);
            $match = $context->builder->icmp(
                Builder::INT_EQ,
                $runtimeClassId,
                $i64->constInt($enumId, false)
            );
            $takeBlock = $fn->appendBasicBlock('array_column_enum_take_'.$enumId.'_'.$tag);
            $nextBlock = $idx === $lastIdx
                ? $done
                : $fn->appendBasicBlock('array_column_enum_try_'.($idx + 1).'_'.$tag);
            $context->builder->branchIf($match, $takeBlock, $nextBlock);

            $context->builder->positionAtEnd($takeBlock);
            $hasBacking = $objectType->enumHasBacking($enumId);
            $nameBb = $fn->appendBasicBlock('array_column_enum_name_'.$enumId.'_'.$tag);
            $valueBb = $fn->appendBasicBlock('array_column_enum_value_'.$enumId.'_'.$tag);
            $context->builder->branchIf($isNameKey, $nameBb, $valueBb);

            $context->builder->positionAtEnd($nameBb);
            self::appendEnumCaseNameSlot($context, $objectType, $dest, $objPtr, $done, $tag.'_n'.$enumId);

            $context->builder->positionAtEnd($valueBb);
            if ($hasBacking) {
                self::appendEnumCaseValueSlot($context, $objectType, $dest, $objPtr, $done, $tag.'_v'.$enumId);
            } else {
                $context->builder->branch($done);
            }

            $checkBlock = $nextBlock;
        }
        if ($checkBlock !== $done) {
            $context->builder->positionAtEnd($checkBlock);
            $context->builder->branch($done);
        }
    }

    private static function appendEnumCaseNameSlot(
        Context $context,
        JitObjectType $objectType,
        Value $dest,
        Value $objPtr,
        $done,
        string $tag
    ): void {
        $slot = $objectType->enumCaseBuiltinPropertySlotPtr(
            $objPtr,
            EnumCasePropertyJitHelper::SLOT_NAME
        );
        $nameStr = $context->builder->pointerCast(
            $context->builder->load($slot),
            $context->getTypeFromString('__string__*')
        );
        $cellSlot = JitValueBox::alloc($context);
        $cellPtr = JitValueBox::pointer($context, $cellSlot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $cellPtr,
            $nameStr
        );
        $cellVar = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $cellSlot);
        $htMap = $context->structFieldMap['__hashtable__'];
        $index = $context->builder->load($context->builder->structGep($dest, $htMap['nextFreeElement']));
        HashTableHelper::setAtIndex($context, $dest, $index, $cellVar);
        $context->builder->branch($done);
    }

    private static function appendEnumCaseValueSlot(
        Context $context,
        JitObjectType $objectType,
        Value $dest,
        Value $objPtr,
        $done,
        string $tag
    ): void {
        $slot = $objectType->enumCaseBuiltinPropertySlotPtr(
            $objPtr,
            EnumCasePropertyJitHelper::SLOT_VALUE
        );
        $cellSlot = JitValueBox::alloc($context);
        $cellPtr = JitValueBox::pointer($context, $cellSlot);
        $context->builder->call(
            $context->lookupFunction('__object__load_value_slot'),
            $slot,
            $cellPtr
        );
        $cellVar = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $cellSlot);
        $htMap = $context->structFieldMap['__hashtable__'];
        $index = $context->builder->load($context->builder->structGep($dest, $htMap['nextFreeElement']));
        HashTableHelper::setAtIndex($context, $dest, $index, $cellVar);
        $context->builder->branch($done);
    }
}
