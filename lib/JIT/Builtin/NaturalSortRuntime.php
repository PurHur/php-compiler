<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;

/**
 * JIT/AOT link for natsort()/natcasesort() via NaturalSortJitHelper PHP (#12753).
 *
 * Standalone AOT compiles {@see NaturalSortJitHelper} via JitVmHelperLink bridge (#14529); native literal arrays materialize to hashtable then route through PHP (#18407).
 * SSOT: {@see \PHPCompiler\ext\standard\VmArray::natsortCopy()} /
 * {@see \PHPCompiler\ext\standard\VmArray::natcasesortCopy()}
 * php-src: ext/standard/array.c — php_natsort / php_natcasesort
 */
final class NaturalSortRuntime
{
    private const ABI_NATSORT = '__natsort__by_value';

    private const ABI_NATCASESORT = '__natcasesort__by_value';

    private const HELPER_PATH = '/ext/standard/NaturalSortJitHelper.php';

    private const NATSORT_HELPER = 'PHPCompiler\\ext\\standard\\NaturalSortJitHelper::natsortByValue';

    private const NATCASESORT_HELPER = 'PHPCompiler\\ext\\standard\\NaturalSortJitHelper::natcasesortByValue';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::NATSORT_HELPER,
        self::NATCASESORT_HELPER,
    ];

    public static function natsortByValue(Context $context, JITVariable $array): void
    {
        self::invokeNaturalSort($context, $array, self::ABI_NATSORT);
    }

    public static function natcasesortByValue(Context $context, JITVariable $array): void
    {
        self::invokeNaturalSort($context, $array, self::ABI_NATCASESORT);
    }

    private static function invokeNaturalSort(Context $context, JITVariable $array, string $abi): void
    {
        self::ensureLinked($context);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $context->builder->call($context->lookupFunction($abi), $ht);
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
        }
    }

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
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
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_NATSORT,
            'natsort_by_value_bridge_entry',
            [$htPtr],
            $void,
            self::NATSORT_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#12753'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_NATCASESORT,
            'natcasesort_by_value_bridge_entry',
            [$htPtr],
            $void,
            self::NATCASESORT_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#12753'
        );
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach ([self::ABI_NATSORT, self::ABI_NATCASESORT] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after NaturalSortRuntime bridge (#12753)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
