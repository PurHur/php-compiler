<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\JitArrayIsList;
use PHPLLVM\Builder;

/**
 * JIT/AOT link for ksort()/krsort() (#12770, #18381, #27227).
 *
 * NestedJIT {@see \PHPCompiler\ext\standard\KeySortJitHelper} aborts under thin
 * standalone AOT (VmArray / HashTable method stubs — peer NaturalSort #26975).
 * Emit string-key bubble sorts in {@see Type\HashTable}; list-shaped krsort
 * rebuilds keys n-1..0 in LLVM (#10836).
 *
 * SSOT (VM): {@see \PHPCompiler\ext\standard\VmArray::ksortCopy()} /
 * {@see \PHPCompiler\ext\standard\VmArray::krsortCopy()}
 * php-src: ext/standard/array.c — php_array_ksort / php_array_krsort
 */
final class KeySortRuntime
{
    private const ABI_KSORT = '__hashtable__sortStringKeys';

    private const ABI_KSORT_LOCALE = '__hashtable__sortStringKeysLocale';

    private const ABI_KRSORT = '__hashtable__sortStringKeysReverse';

    public static function ksortByKey(Context $context, JITVariable $array): void
    {
        self::invokeKeySortSkipList($context, $array, self::ABI_KSORT);
    }

    public static function ksortByKeyLocale(Context $context, JITVariable $array): void
    {
        self::invokeKeySortSkipList($context, $array, self::ABI_KSORT_LOCALE);
    }

    public static function krsortByKey(Context $context, JITVariable $array): void
    {
        self::ensureLinked($context);
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            throw new \LogicException(
                'krsort() cannot compile fixed-size literal arrays in JIT/AOT yet; use bin/vm.php or bin/serve.php'
            );
        }
        $isList = JitArrayIsList::invoke($context, $array);
        $done = BasicBlockHelper::append($context, 'krsort_done');
        $sortList = BasicBlockHelper::append($context, 'krsort_sort_list');
        $sort = BasicBlockHelper::append($context, 'krsort_sort');
        $context->builder->branchIf($isList, $sortList, $sort);

        $context->builder->positionAtEnd($sortList);
        self::krsortPackedListByKey($context, $array);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($sort);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $context->builder->call($context->lookupFunction(self::ABI_KRSORT), $ht);
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
        }
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    private static function invokeKeySortSkipList(Context $context, JITVariable $array, string $abi): void
    {
        self::ensureLinked($context);
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            throw new \LogicException(
                'ksort() cannot compile fixed-size literal arrays in JIT/AOT yet; use bin/vm.php or bin/serve.php'
            );
        }
        $isList = JitArrayIsList::invoke($context, $array);
        $done = BasicBlockHelper::append($context, 'ksort_done');
        $sort = BasicBlockHelper::append($context, 'ksort_sort');
        $context->builder->branchIf($isList, $done, $sort);

        $context->builder->positionAtEnd($sort);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $context->builder->call($context->lookupFunction($abi), $ht);
        // In-place HT mutation; unconditional store corrupts thin AOT value boxes (#27227).
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
        }
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    /**
     * krsort() on dense 0..n-1 lists — rebuild with keys n-1..0 (php-src; #10836).
     */
    private static function krsortPackedListByKey(Context $context, JITVariable $array): void
    {
        $src = ArrayBuiltinHelper::loadHashTable($context, $array);
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $two = $sizeT->constInt(2, false);
        $zeroI64 = $i64->constInt(0, false);
        $oneI64 = $i64->constInt(1, false);
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $src
        );
        $tooSmall = $context->builder->icmp(Builder::INT_ULT, $num, $two);
        $done = BasicBlockHelper::append($context, 'krsort_packed_list_done');
        $work = BasicBlockHelper::append($context, 'krsort_packed_list_work');
        $context->builder->branchIf($tooSmall, $done, $work);

        $context->builder->positionAtEnd($work);
        $dest = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $kSlot = $context->builder->alloca($i64, 1, 'krsort_packed_k');
        $numI64 = $context->builder->zExt($num, $i64);
        $context->builder->store($context->builder->subNoSignedWrap($numI64, $oneI64), $kSlot);

        $loopHead = BasicBlockHelper::append($context, 'krsort_packed_head');
        $loopBody = BasicBlockHelper::append($context, 'krsort_packed_body');
        $loopNext = BasicBlockHelper::append($context, 'krsort_packed_next');
        $storeDone = BasicBlockHelper::append($context, 'krsort_packed_store_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $k = $context->builder->load($kSlot);
        $kNegative = $context->builder->icmp(Builder::INT_SLT, $k, $zeroI64);
        $context->builder->branchIf($kNegative, $storeDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $kIndex = $context->builder->truncOrBitCast($k, $sizeT);
        $valueBox = HashTableHelper::readIndexedToValueBox($context, $src, $kIndex);
        $keyStr = JitNativeString::formatIndexKey($context, $k);
        HashTableHelper::setAtStringKey($context, $dest, $keyStr, $valueBox);
        $context->builder->branch($loopNext);

        $context->builder->positionAtEnd($loopNext);
        $context->builder->store($context->builder->subNoSignedWrap($k, $oneI64), $kSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($storeDone);
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $dest);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    public static function ensureLinked(Context $context): void
    {
        self::assertAbi($context, self::ABI_KSORT);
        self::assertAbi($context, self::ABI_KSORT_LOCALE);
        self::assertAbi($context, self::ABI_KRSORT);
    }

    private static function assertAbi(Context $context, string $name): void
    {
        $fn = $context->module->getNamedFunction($name);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException($name.' missing after HashTable type init (#27227)');
        }
        $context->registerFunction($name, $fn);
    }
}
