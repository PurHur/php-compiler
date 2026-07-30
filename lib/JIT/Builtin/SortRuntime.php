<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;

/**
 * JIT/AOT link for sort()/rsort() (#12769, #24010).
 *
 * SORT_REGULAR / reverse use LLVM `__hashtable__sortPacked*` (walks packed
 * nextFreeElement slots). Locale/natural bridge SortJitHelper PHP.
 * Associative-key reindex for n<2 is handled on the VM execute path (#25385);
 * NestedJIT helpers cannot yet rewrite string-key hashtables under thin AOT.
 *
 * SSOT: {@see \PHPCompiler\ext\standard\VmArray::sortPackedInPlace()} /
 * {@see \PHPCompiler\ext\standard\VmArray::sortPackedReverseInPlace()}
 * php-src: ext/standard/array.c — php_array_sort
 */
final class SortRuntime
{
    private const ABI_SORT_PACKED = '__hashtable__sortPacked';

    private const ABI_SORT_PACKED_REVERSE = '__hashtable__sortPackedReverse';

    private const ABI_SORT_PACKED_LOCALE = '__sort__packed_locale';

    private const ABI_SORT_PACKED_NATURAL = '__sort__packed_natural';

    private const ABI_SORT_PACKED_NATURAL_CASE = '__sort__packed_natural_case';

    private const HELPER_PATH = '/ext/standard/SortJitHelper.php';

    private const SORT_PACKED_LOCALE_HELPER = 'PHPCompiler\\ext\\standard\\SortJitHelper::sortPackedLocale';

    private const SORT_PACKED_NATURAL_HELPER = 'PHPCompiler\\ext\\standard\\SortJitHelper::sortPackedNatural';

    private const SORT_PACKED_NATURAL_CASE_HELPER = 'PHPCompiler\\ext\\standard\\SortJitHelper::sortPackedNaturalCase';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::SORT_PACKED_LOCALE_HELPER,
        self::SORT_PACKED_NATURAL_HELPER,
        self::SORT_PACKED_NATURAL_CASE_HELPER,
    ];

    public static function sortPacked(Context $context, JITVariable $array): void
    {
        self::invokeLlvmPackedSort($context, $array, self::ABI_SORT_PACKED);
    }

    public static function sortPackedLocale(Context $context, JITVariable $array): void
    {
        self::invokeHelperPackedSort($context, $array, self::ABI_SORT_PACKED_LOCALE);
    }

    public static function sortPackedNatural(Context $context, JITVariable $array): void
    {
        self::invokeHelperPackedSort($context, $array, self::ABI_SORT_PACKED_NATURAL);
    }

    public static function sortPackedNaturalCase(Context $context, JITVariable $array): void
    {
        self::invokeHelperPackedSort($context, $array, self::ABI_SORT_PACKED_NATURAL_CASE);
    }

    public static function sortPackedReverse(Context $context, JITVariable $array): void
    {
        self::invokeLlvmPackedSort($context, $array, self::ABI_SORT_PACKED_REVERSE);
    }

    private static function invokeLlvmPackedSort(Context $context, JITVariable $array, string $abi): void
    {
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $context->builder->call($context->lookupFunction($abi), $ht);
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
    }

    private static function invokeHelperPackedSort(Context $context, JITVariable $array, string $abi): void
    {
        self::ensureLocaleNaturalLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $context->builder->call($context->lookupFunction($abi), $ht);
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
    }

    public static function ensureLinked(Context $context): void
    {
        // Regular/reverse paths use Type\HashTable builtins registered at context init.
    }

    public static function ensureLocaleNaturalLinked(Context $context): void
    {
        self::implementLocaleNatural($context);
    }

    /** @deprecated Use ensureLocaleNaturalLinked — kept for call-site compatibility. */
    public static function implement(Context $context): void
    {
        self::implementLocaleNatural($context);
    }

    private static function implementLocaleNatural(Context $context): void
    {
        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $void = $context->getTypeFromString('void');
        $bridges = [
            [self::ABI_SORT_PACKED_LOCALE, 'sort_packed_locale_bridge_entry', self::SORT_PACKED_LOCALE_HELPER],
            [self::ABI_SORT_PACKED_NATURAL, 'sort_packed_natural_bridge_entry', self::SORT_PACKED_NATURAL_HELPER],
            [self::ABI_SORT_PACKED_NATURAL_CASE, 'sort_packed_natural_case_bridge_entry', self::SORT_PACKED_NATURAL_CASE_HELPER],
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

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
