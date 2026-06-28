<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;

/**
 * JIT/AOT link for ksort()/krsort() via KeySortJitHelper PHP (#12770).
 *
 * Embed and standalone AOT compile the same PHP bridge (#13050).
 * SSOT: {@see \PHPCompiler\ext\standard\VmArray::ksortCopy()} /
 * {@see \PHPCompiler\ext\standard\VmArray::krsortCopy()}
 * php-src: ext/standard/array.c — php_array_ksort / php_array_krsort
 */
final class KeySortRuntime
{
    private const ABI_KSORT = '__ksort__by_key';

    private const ABI_KSORT_LOCALE = '__ksort__by_key_locale';

    private const ABI_KRSORT = '__krsort__by_key';

    private const HELPER_PATH = '/ext/standard/KeySortJitHelper.php';

    private const KSORT_HELPER = 'PHPCompiler\\ext\\standard\\KeySortJitHelper::ksortByKey';

    private const KSORT_LOCALE_HELPER = 'PHPCompiler\\ext\\standard\\KeySortJitHelper::ksortByKeyLocale';

    private const KRSORT_HELPER = 'PHPCompiler\\ext\\standard\\KeySortJitHelper::krsortByKey';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::KSORT_HELPER,
        self::KSORT_LOCALE_HELPER,
        self::KRSORT_HELPER,
    ];

    public static function ksortByKey(Context $context, JITVariable $array): void
    {
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            ArrayBuiltinHelper::ksortByKey($context, $array);

            return;
        }

        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $context->builder->call($context->lookupFunction(self::ABI_KSORT), $ht);
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
    }

    public static function ksortByKeyLocale(Context $context, JITVariable $array): void
    {
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            ArrayBuiltinHelper::ksortByKeyLocale($context, $array);

            return;
        }

        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $context->builder->call($context->lookupFunction(self::ABI_KSORT_LOCALE), $ht);
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
    }

    public static function krsortByKey(Context $context, JITVariable $array): void
    {
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            ArrayBuiltinHelper::krsortByKey($context, $array);

            return;
        }

        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $context->builder->call($context->lookupFunction(self::ABI_KRSORT), $ht);
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
            [self::ABI_KSORT, 'ksort_by_key_bridge_entry', self::KSORT_HELPER],
            [self::ABI_KSORT_LOCALE, 'ksort_by_key_locale_bridge_entry', self::KSORT_LOCALE_HELPER],
            [self::ABI_KRSORT, 'krsort_by_key_bridge_entry', self::KRSORT_HELPER],
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
                '#12770'
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
        foreach ([self::ABI_KSORT, self::ABI_KSORT_LOCALE, self::ABI_KRSORT] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after KeySortRuntime bridge (#12770)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
