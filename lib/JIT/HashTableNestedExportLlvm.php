<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Nested JIT lowering for {@see \PHPCompiler\VM\HashTable::exportKeyValuePairs()} (#12910).
 *
 * Helpers receive {@see __hashtable__*} bitcast as HashTable objects; instance iteration
 * is lowered to LLVM pair export instead of compiling lib/VM/HashTable.php in nested scope.
 */
final class HashTableNestedExportLlvm
{
    public const PROXY_NAME = 'phpcompiler\\vm\\hashtable::exportkeyvaluepairs';

    public static function ensureLinked(Context $context): void
    {
        if ($context->functionIsRegistered(self::PROXY_NAME)) {
            return;
        }
        $context->functionProxies[strtolower(self::PROXY_NAME)] = new Call\HashTableExportKeyValuePairs();
    }
}
