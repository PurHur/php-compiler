<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * Lowered into JIT embed modules for strtr() array form (#9392, php-in-PHP).
 *
 * SSOT: {@see VmString::strtrArray()}
 * Standalone AOT uses {@see StringStrtrStandaloneLlvm} until HashTable iteration compiles natively.
 */
final class StrtrArrayJitHelper
{
    public static function strtrArray(string $subject, HashTable $replacePairs): string
    {
        $pairs = [];
        foreach ($replacePairs->iterateKeyed(true) as [$keyVar, $valueVar]) {
            $pairs[VmString::coerceStringBuiltinArg($keyVar, 'strtr', 1, 'replace_pairs')] =
                VmString::coerceStringBuiltinArg($valueVar, 'strtr', 1, 'replace_pairs');
        }

        return VmString::strtrArray($subject, $pairs);
    }
}
