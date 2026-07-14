<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StreamIoRuntime;

/**
 * Nested JIT lowering for {@see \PHPCompiler\VM\HashTable} instance helpers (#14601).
 *
 * JitHelper PHP receives {@see __hashtable__*} bitcast as HashTable; method bodies must
 * lower to LLVM — not compile lib/VM/HashTable.php in nested scope (#12910 pattern).
 */
final class NestedVmHashTableMethodLlvm
{
    /** @var array<string, class-string<Call>|string> */
    private const METHOD_HANDLERS = [
        'add' => Call\HashTableAdd::class,
        'append' => Call\HashTableAppend::class,
        'updateindex' => Call\HashTableUpdateIndex::class,
        'getnumelements' => Call\HashTableGetNumElements::class,
        'padcopy' => Call\HashTablePadCopy::class,
        'valuescopy' => Call\HashTableValuesCopy::class,
        'keyscopy' => Call\HashTableKeysCopy::class,
        'keysmatchingcopy' => Call\HashTableKeysMatchingCopy::class,
        'exportkeyvaluepairs' => Call\HashTableExportKeyValuePairs::class,
        'iterate' => Call\HashTableIterate::class,
        'unshiftprepend' => Call\HashTableUnshiftPrepend::class,
        'findindex' => Call\HashTableFindIndex::class,
        'add' => Call\HashTableWriteNested::class,
        'updateindex' => Call\HashTableWriteNested::class,
        'append' => Call\HashTableWriteNested::class,
    ];

    public static function ensureMethod(Context $context, string $methodLc): bool
    {
        if (StreamIoRuntime::shouldDeferHeavyStreamIoEmitters($context)
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
