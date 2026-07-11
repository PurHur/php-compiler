<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;

/**
 * JIT/AOT link for asort()/arsort() via ValueSortJitHelper PHP (#12771).
 *
 * Embed and standalone AOT compile the same PHP bridge (#13053).
 * SSOT: {@see \PHPCompiler\ext\standard\VmArray::asortCopy()} /
 * {@see \PHPCompiler\ext\standard\VmArray::arsortCopy()}
 * php-src: ext/standard/array.c — php_array_asort / php_array_arsort
 */
final class ValueSortRuntime
{
    private const ABI_ASORT = '__asort__by_value';

    private const ABI_ASORT_LOCALE = '__asort__by_value_locale';

    private const ABI_ARSORT = '__arsort__by_value';

    private const HELPER_PATH = '/ext/standard/ValueSortJitHelper.php';

    private const ASORT_HELPER = 'PHPCompiler\\ext\\standard\\ValueSortJitHelper::asortByValue';

    private const ASORT_LOCALE_HELPER = 'PHPCompiler\\ext\\standard\\ValueSortJitHelper::asortByValueLocale';

    private const ARSORT_HELPER = 'PHPCompiler\\ext\\standard\\ValueSortJitHelper::arsortByValue';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ASORT_HELPER,
        self::ASORT_LOCALE_HELPER,
        self::ARSORT_HELPER,
    ];

    public static function asortByValue(Context $context, JITVariable $array): void
    {
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            ArrayBuiltinHelper::asortByValue($context, $array);

            return;
        }

        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $context->builder->call($context->lookupFunction(self::ABI_ASORT), $ht);
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
    }

    public static function asortByValueLocale(Context $context, JITVariable $array): void
    {
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            ArrayBuiltinHelper::asortByValueLocale($context, $array);

            return;
        }

        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $context->builder->call($context->lookupFunction(self::ABI_ASORT_LOCALE), $ht);
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
    }

    public static function arsortByValue(Context $context, JITVariable $array): void
    {
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            ArrayBuiltinHelper::arsortByValue($context, $array);

            return;
        }

        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $context->builder->call($context->lookupFunction(self::ABI_ARSORT), $ht);
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
            [self::ABI_ASORT, 'asort_by_value_bridge_entry', self::ASORT_HELPER],
            [self::ABI_ASORT_LOCALE, 'asort_by_value_locale_bridge_entry', self::ASORT_LOCALE_HELPER],
            [self::ABI_ARSORT, 'arsort_by_value_bridge_entry', self::ARSORT_HELPER],
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
                '#12771'
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
        foreach ([self::ABI_ASORT, self::ABI_ASORT_LOCALE, self::ABI_ARSORT] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after ValueSortRuntime bridge (#12771)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
