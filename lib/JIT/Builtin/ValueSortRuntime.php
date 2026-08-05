<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\Variable as JITVariable;

/**
 * JIT/AOT link for asort()/arsort() (#12771, #27227).
 *
 * NestedJIT {@see \PHPCompiler\ext\standard\ValueSortJitHelper} aborts under thin
 * standalone AOT (same HashTable-method stub class as KeySort / NaturalSort #26975).
 * Emit string-key value bubble sorts already in {@see Type\HashTable}.
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
        self::invokeByValueSort($context, $array, self::ABI_ASORT);
    }

    public static function asortByValueLocale(Context $context, JITVariable $array): void
    {
        self::invokeByValueSort($context, $array, self::ABI_ASORT_LOCALE);
    }

    public static function arsortByValue(Context $context, JITVariable $array): void
    {
        self::invokeByValueSort($context, $array, self::ABI_ARSORT);
    }

    private static function invokeByValueSort(Context $context, JITVariable $array, string $abi): void
    {
        self::ensureLinked($context);
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            throw new \LogicException(
                'asort()/arsort() cannot compile fixed-size literal arrays in JIT/AOT yet; use bin/vm.php or bin/serve.php'
            );
        }
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $context->builder->call($context->lookupFunction($abi), $ht);
        // In-place mutation via HT pointer; store only native slots (peer NaturalSort #26975).
        // Unconditional store corrupts thin-standalone value-boxed arrays (#27227).
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
        }
    }

    public static function ensureLinked(Context $context): void
    {
        self::assertAbi($context, self::ABI_ASORT);
        self::assertAbi($context, self::ABI_ASORT_LOCALE);
        self::assertAbi($context, self::ABI_ARSORT);
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
