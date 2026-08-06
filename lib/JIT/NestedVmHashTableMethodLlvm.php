<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Nested JIT lowering for {@see \PHPCompiler\VM\HashTable} instance helpers (#14601).
 *
 * JitHelper PHP receives {@see __hashtable__*} bitcast as HashTable; method bodies must
 * lower to LLVM — not compile lib/VM/HashTable.php in nested scope (#12910 pattern).
 *
 * keysCopy / keysMatchingCopy / valuesCopy lower via {@see HashTableKeysLlvm} /
 * {@see HashTableKeysMatchingLlvm} / {@see HashTableValuesLlvm} (peer reverse/slice) —
 * not ArrayKeys/ArrayValues NestedJIT bridges (#27211 / #27212 / #27544).
 *
 * COW: `duplicate` / `unionCopy` (#23548) lower via {@see HashTableCowLlvm} — not the
 * HashTable*Runtime bridges that NestedJIT-compile HashTableJitHelper (would recurse).
 */
final class NestedVmHashTableMethodLlvm
{
    /** @var array<string, class-string<Call>|string> */
    private const METHOD_HANDLERS = [
        'getnumelements' => Call\HashTableGetNumElements::class,
        'padcopy' => Call\HashTablePadCopy::class,
        // NestedJIT-safe keys/values for ArrayKeys/ArrayValues (#27211 / #27212).
        'valuescopy' => Call\HashTableValuesCopy::class,
        'keyscopy' => Call\HashTableKeysCopy::class,
        'keysmatchingcopy' => Call\HashTableKeysMatchingCopy::class,
        'exportkeyvaluepairs' => Call\HashTableExportKeyValuePairs::class,
        'ispackedlist' => Call\HashTableIsPackedList::class,
        'comparespaceship' => Call\HashTableCompareSpaceship::class,
        'iterate' => Call\HashTableIterate::class,
        // NestedJIT-safe: same pair-list materialization as exportKeyValuePairs (#23974 / #12908).
        'iteratekeyed' => Call\HashTableExportKeyValuePairs::class,
        'slicecopy' => Call\HashTableSliceCopy::class,
        // NestedJIT-safe chunk for ArrayChunkJitHelper (#27074).
        'chunkcopy' => Call\HashTableChunkCopy::class,
        // NestedJIT-safe reverse for ArrayReverseJitHelper (#27067).
        'reversecopy' => Call\HashTableReverseCopy::class,
        // NestedJIT-safe splice for ArraySpliceJitHelper (#27075).
        'spliceinplace' => Call\HashTableSpliceInPlace::class,
        'mergestringkeysfrom' => Call\HashTableMergeStringKeysFrom::class,
        'unshiftprepend' => Call\HashTableUnshiftPrepend::class,
        // NestedJIT-safe packed list shift for ArrayShiftJitHelper (#24025).
        'shiftfirst' => Call\HashTableShiftFirst::class,
        // NestedJIT-safe packed list pop for ArrayPopJitHelper (#27214).
        'poplast' => Call\HashTablePopLast::class,
        'find' => Call\HashTableFind::class,
        'findindex' => Call\HashTableFindIndex::class,
        // COW duplicate / array union for HashTableJitHelper NestedJIT (#23548).
        'duplicate' => Call\HashTableDuplicate::class,
        'unioncopy' => Call\HashTableUnionCopy::class,
        // array_replace NestedJIT (#27519) — LLVM via HashTableCowLlvm, not HashTable.php.
        'replacecopy' => Call\HashTableReplaceCopy::class,
        // array_replace_recursive NestedJIT (#26977) — LLVM, not HashTable.php.
        'replacerecursivecopy' => Call\HashTableReplaceRecursiveCopy::class,
        // Writes share one NestedJIT proxy (#14601).
        'add' => Call\HashTableWriteNested::class,
        'addindex' => Call\HashTableWriteNested::class,
        // String-key overwrite used by ArrayFlipJitHelper / ArrayColumnJitHelper (#26970).
        'update' => Call\HashTableWriteNested::class,
        'updateindex' => Call\HashTableWriteNested::class,
        'append' => Call\HashTableWriteNested::class,
        // In-place mutators for usort/asort peers (#24157).
        'replacepackedvalues' => Call\HashTableMutateNested::class,
        'assignpackedlist' => Call\HashTableMutateNested::class,
        'reorderkeyedpairs' => Call\HashTableMutateNested::class,
    ];

    public static function ensureMethod(Context $context, string $methodLc): bool
    {
        $handler = self::METHOD_HANDLERS[$methodLc] ?? null;
        if (null === $handler) {
            return false;
        }
        $proxyName = 'phpcompiler\\vm\\hashtable::'.$methodLc;
        if ($context->functionIsRegistered($proxyName)) {
            return true;
        }
        if (Call\HashTableWriteNested::class === $handler) {
            $context->functionProxies[$proxyName] = new Call\HashTableWriteNested($methodLc);
        } elseif (Call\HashTableMutateNested::class === $handler) {
            $context->functionProxies[$proxyName] = new Call\HashTableMutateNested($methodLc);
        } else {
            $context->functionProxies[$proxyName] = new $handler();
        }

        return true;
    }

    public static function isNestedHashTableMethod(string $methodLc): bool
    {
        return isset(self::METHOD_HANDLERS[$methodLc]);
    }
}
