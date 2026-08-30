<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\ValueSortKeyedLlvm;
use PHPCompiler\JIT\Variable as JITVariable;

/**
 * JIT/AOT link for asort()/arsort() (#12771, #27227, #33620, #34707).
 *
 * NestedJIT {@see \PHPCompiler\ext\standard\ValueSortJitHelper} aborts under thin
 * standalone AOT (same HashTable-method stub class as KeySort / NaturalSort #26975).
 *
 * SORT_REGULAR / reverse / SORT_*|SORT_FLAG_CASE: always {@see ValueSortKeyedLlvm}
 * (export pairs → value sort → reorderKeyedPairs). The older
 * `__hashtable__sortStringKeyValues*` ABIs only walk `strKeys` and no-op on packed
 * `0..n-1` lists — silent wrong keys (#33620).
 *
 * SORT_LOCALE_STRING: keep the strKey locale ABI (string-key maps).
 *
 * SSOT (VM): {@see \PHPCompiler\ext\standard\VmArray::asortCopy()} /
 * {@see \PHPCompiler\ext\standard\VmArray::arsortCopy()}
 * php-src: ext/standard/array.c — php_array_asort / php_array_arsort
 */
final class ValueSortRuntime
{
    private const ABI_ASORT = '__hashtable__sortStringKeyValues';

    private const ABI_ASORT_LOCALE = '__hashtable__sortStringKeyValuesLocale';

    private const ABI_ARSORT = '__hashtable__sortStringKeyValuesReverse';

    public static function asortByValue(Context $context, JITVariable $array): void
    {
        self::sortPreserveKeys($context, $array, false, false);
    }

    /** SORT_STRING|SORT_FLAG_CASE / SORT_REGULAR|SORT_FLAG_CASE (#34707). */
    public static function asortByValueCase(Context $context, JITVariable $array): void
    {
        self::sortPreserveKeys($context, $array, false, true);
    }

    public static function asortByValueLocale(Context $context, JITVariable $array): void
    {
        $context->type->hashtable->ensureSortAbi(self::ABI_ASORT_LOCALE);
        self::invokeStrKeyAbi($context, $array, self::ABI_ASORT_LOCALE);
    }

    public static function arsortByValue(Context $context, JITVariable $array): void
    {
        self::sortPreserveKeys($context, $array, true, false);
    }

    /** arsort() SORT_*|SORT_FLAG_CASE (#34707). */
    public static function arsortByValueCase(Context $context, JITVariable $array): void
    {
        self::sortPreserveKeys($context, $array, true, true);
    }

    private static function sortPreserveKeys(
        Context $context,
        JITVariable $array,
        bool $reverse,
        bool $caseInsensitive
    ): void {
        self::ensureLinked($context);
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            throw new \LogicException(
                'asort()/arsort() cannot compile fixed-size literal arrays in JIT/AOT yet; use bin/vm.php or bin/serve.php'
            );
        }
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        ValueSortKeyedLlvm::sortValuesPreserveKeys($context, $ht, $reverse, $caseInsensitive);
        // In-place mutation via HT pointer; store only native slots (peer NaturalSort #26975).
        // Unconditional store corrupts thin-standalone value-boxed arrays (#27227).
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
        }
    }

    private static function invokeStrKeyAbi(Context $context, JITVariable $array, string $abi): void
    {
        self::ensureLinked($context);
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            throw new \LogicException(
                'asort()/arsort() cannot compile fixed-size literal arrays in JIT/AOT yet; use bin/vm.php or bin/serve.php'
            );
        }
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $context->builder->call($context->lookupFunction($abi), $ht);
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
        }
    }

    public static function ensureLinked(Context $context): void
    {
        self::assertAbi($context, self::ABI_ASORT);
        self::assertAbi($context, self::ABI_ARSORT);
        // locale ABI is ensureSortAbi on asortByValueLocale only (#35904).
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
