<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * Lowered into JIT embed modules for strtr() array form (#9392, php-in-PHP).
 *
 * SSOT: {@see VmString::strtrArrayFromHashTable()}
 * JIT embed and AOT standalone compile this helper via nested JIT (#9392, #12908).
 */
final class StrtrArrayJitHelper
{
    public static function strtrArray(string $subject, HashTable $replacePairs): string
    {
        return VmString::strtrArrayFromHashTable($subject, $replacePairs);
    }
}
