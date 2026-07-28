<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Nested JIT lowering for {@see \PHPCompiler\VM\HashTable} instance helpers (#14601).
 *
 * JitHelper PHP receives {@see __hashtable__*} bitcast as HashTable; method bodies must
 * lower to LLVM — not compile lib/VM/HashTable.php in nested scope (#12910 pattern).
 *
 * Thin standalone AOT (`isThinStandaloneAotMain`, #20533 / #15417): skip keysCopy/valuesCopy
 * registration so ArrayKeys/ArrayValues NestedJIT is not pulled into user-script init.
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
        'mergestringkeysfrom' => Call\HashTableMergeStringKeysFrom::class,
        'unshiftprepend' => Call\HashTableUnshiftPrepend::class,
        // NestedJIT-safe packed list shift for ArrayShiftJitHelper (#24025).
        'shiftfirst' => Call\HashTableShiftFirst::class,
        'find' => Call\HashTableFind::class,
        'findindex' => Call\HashTableFindIndex::class,
        // COW duplicate / array union for HashTableJitHelper NestedJIT (#23548).
        'duplicate' => Call\HashTableDuplicate::class,
        'unioncopy' => Call\HashTableUnionCopy::class,
        // Writes share one NestedJIT proxy (#14601).
        'add' => Call\HashTableWriteNested::class,
        'addindex' => Call\HashTableWriteNested::class,
        'updateindex' => Call\HashTableWriteNested::class,
        'append' => Call\HashTableWriteNested::class,
        // In-place mutators for usort/asort peers (#24157).
        'replacepackedvalues' => Call\HashTableMutateNested::class,
        'assignpackedlist' => Call\HashTableMutateNested::class,
        'reorderkeyedpairs' => Call\HashTableMutateNested::class,
    ];

    public static function ensureMethod(Context $context, string $methodLc): bool
    {
        if ($context->isThinStandaloneAotMain()
            && ('keyscopy' === $methodLc || 'valuescopy' === $methodLc)) {
            return false;
        }
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
