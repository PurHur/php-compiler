<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;

/**
 * JIT/AOT link for sort()/rsort() via SortJitHelper PHP (#12769).
 *
 * Embed and standalone AOT compile the same PHP bridge (#13049).
 * SSOT: {@see \PHPCompiler\ext\standard\VmArray::sortPackedInPlace()} /
 * {@see \PHPCompiler\ext\standard\VmArray::sortPackedReverseInPlace()}
 * php-src: ext/standard/array.c — php_array_sort
 */
final class SortRuntime
{
    private const ABI_SORT_PACKED = '__sort__packed';

    private const ABI_SORT_PACKED_LOCALE = '__sort__packed_locale';

    private const ABI_SORT_PACKED_NATURAL = '__sort__packed_natural';

    private const ABI_SORT_PACKED_NATURAL_CASE = '__sort__packed_natural_case';

    private const ABI_SORT_PACKED_REVERSE = '__sort__packed_reverse';

    private const HELPER_PATH = '/ext/standard/SortJitHelper.php';

    private const SORT_PACKED_HELPER = 'PHPCompiler\\ext\\standard\\SortJitHelper::sortPacked';

    private const SORT_PACKED_LOCALE_HELPER = 'PHPCompiler\\ext\\standard\\SortJitHelper::sortPackedLocale';

    private const SORT_PACKED_NATURAL_HELPER = 'PHPCompiler\\ext\\standard\\SortJitHelper::sortPackedNatural';

    private const SORT_PACKED_NATURAL_CASE_HELPER = 'PHPCompiler\\ext\\standard\\SortJitHelper::sortPackedNaturalCase';

    private const SORT_PACKED_REVERSE_HELPER = 'PHPCompiler\\ext\\standard\\SortJitHelper::sortPackedReverse';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::SORT_PACKED_HELPER,
        self::SORT_PACKED_LOCALE_HELPER,
        self::SORT_PACKED_NATURAL_HELPER,
        self::SORT_PACKED_NATURAL_CASE_HELPER,
        self::SORT_PACKED_REVERSE_HELPER,
    ];

    public static function sortPacked(Context $context, JITVariable $array): void
    {
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            ArrayBuiltinHelper::sortPacked($context, $array);

            return;
        }

        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $context->builder->call($context->lookupFunction(self::ABI_SORT_PACKED), $ht);
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
    }

    public static function sortPackedLocale(Context $context, JITVariable $array): void
    {
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            ArrayBuiltinHelper::sortPackedLocale($context, $array);

            return;
        }

        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $context->builder->call($context->lookupFunction(self::ABI_SORT_PACKED_LOCALE), $ht);
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
    }

    public static function sortPackedNatural(Context $context, JITVariable $array): void
    {
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            ArrayBuiltinHelper::sortPackedNatural($context, $array);

            return;
        }

        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $context->builder->call($context->lookupFunction(self::ABI_SORT_PACKED_NATURAL), $ht);
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
    }

    public static function sortPackedNaturalCase(Context $context, JITVariable $array): void
    {
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            ArrayBuiltinHelper::sortPackedNaturalCase($context, $array);

            return;
        }

        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $context->builder->call($context->lookupFunction(self::ABI_SORT_PACKED_NATURAL_CASE), $ht);
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
    }

    public static function sortPackedReverse(Context $context, JITVariable $array): void
    {
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            ArrayBuiltinHelper::sortPackedReverse($context, $array);

            return;
        }

        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $context->builder->call($context->lookupFunction(self::ABI_SORT_PACKED_REVERSE), $ht);
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
    }

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $void = $context->getTypeFromString('void');
        $bridges = [
            [self::ABI_SORT_PACKED, 'sort_packed_bridge_entry', self::SORT_PACKED_HELPER],
            [self::ABI_SORT_PACKED_LOCALE, 'sort_packed_locale_bridge_entry', self::SORT_PACKED_LOCALE_HELPER],
            [self::ABI_SORT_PACKED_NATURAL, 'sort_packed_natural_bridge_entry', self::SORT_PACKED_NATURAL_HELPER],
            [self::ABI_SORT_PACKED_NATURAL_CASE, 'sort_packed_natural_case_bridge_entry', self::SORT_PACKED_NATURAL_CASE_HELPER],
            [self::ABI_SORT_PACKED_REVERSE, 'sort_packed_reverse_bridge_entry', self::SORT_PACKED_REVERSE_HELPER],
        ];
        foreach ($bridges as [$abi, $entry, $helper]) {
            JitVmHelperLink::ensureBridge(
                $context,
                $abi,
                $entry,
                [$htPtr],
                $void,
                $helper,
                self::HELPER_PATH,
                self::COMPILED_HELPERS,
                '#12769'
            );
        }
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (
            [
                self::ABI_SORT_PACKED,
                self::ABI_SORT_PACKED_LOCALE,
                self::ABI_SORT_PACKED_NATURAL,
                self::ABI_SORT_PACKED_NATURAL_CASE,
                self::ABI_SORT_PACKED_REVERSE,
            ] as $name
        ) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after SortRuntime bridge (#12769)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
