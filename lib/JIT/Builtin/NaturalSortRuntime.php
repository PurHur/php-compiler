<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\Variable as JITVariable;

/**
 * JIT/AOT link for natsort()/natcasesort() via LLVM packed + string-key natural sorts (#26975).
 *
 * NestedJIT {@see \PHPCompiler\ext\standard\NaturalSortJitHelper} aborts under thin standalone
 * AOT (VmArray / HashTable method stubs — same class as MultisortJitHelper, #26908 / #24010).
 * Emit bubble sorts in {@see Type\HashTable} instead; compare via {@see StringNaturalCompare}.
 *
 * SSOT (VM): {@see \PHPCompiler\ext\standard\VmArray::natsortCopy()} /
 * {@see \PHPCompiler\ext\standard\VmArray::natcasesortCopy()}
 * php-src: ext/standard/array.c — php_natsort / php_natcasesort
 */
final class NaturalSortRuntime
{
    private const ABI_PACKED_NATURAL = '__hashtable__sortPackedNatural';

    private const ABI_PACKED_NATURAL_CASE = '__hashtable__sortPackedNaturalCase';

    private const ABI_STRKEY_NATURAL = '__hashtable__sortStringKeyValuesNatural';

    private const ABI_STRKEY_NATURAL_CASE = '__hashtable__sortStringKeyValuesNaturalCase';

    public static function natsortByValue(Context $context, JITVariable $array): void
    {
        self::invokeNaturalSort($context, $array, false);
    }

    public static function natcasesortByValue(Context $context, JITVariable $array): void
    {
        self::invokeNaturalSort($context, $array, true);
    }

    private static function invokeNaturalSort(Context $context, JITVariable $array, bool $caseInsensitive): void
    {
        self::ensureLinked($context, $caseInsensitive);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $packed = $caseInsensitive ? self::ABI_PACKED_NATURAL_CASE : self::ABI_PACKED_NATURAL;
        $strKey = $caseInsensitive ? self::ABI_STRKEY_NATURAL_CASE : self::ABI_STRKEY_NATURAL;
        // Packed lists (numeric 0..n-1) and string-key maps are distinct representations;
        // each ABI no-ops when its structure is empty / too small (#26975).
        $context->builder->call($context->lookupFunction($packed), $ht);
        $context->builder->call($context->lookupFunction($strKey), $ht);
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
        }
    }

    public static function ensureLinked(Context $context, bool $caseInsensitive = false): void
    {
        if ($caseInsensitive) {
            StringNaturalCompare::ensureStrnatcasecmpLinked($context);
        } else {
            StringNaturalCompare::ensureStrnatcmpLinked($context);
        }
        self::assertAbi($context, $caseInsensitive ? self::ABI_PACKED_NATURAL_CASE : self::ABI_PACKED_NATURAL);
        self::assertAbi($context, $caseInsensitive ? self::ABI_STRKEY_NATURAL_CASE : self::ABI_STRKEY_NATURAL);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        StringNaturalCompare::ensureStandaloneBodies($context);
        self::assertAbi($context, self::ABI_PACKED_NATURAL);
        self::assertAbi($context, self::ABI_PACKED_NATURAL_CASE);
        self::assertAbi($context, self::ABI_STRKEY_NATURAL);
        self::assertAbi($context, self::ABI_STRKEY_NATURAL_CASE);
    }

    private static function assertAbi(Context $context, string $name): void
    {
        $fn = $context->module->getNamedFunction($name);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException($name.' missing after HashTable type init (#26975)');
        }
        $context->registerFunction($name, $fn);
    }
}
