<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Variable as JITVariable;

/**
 * Choose string vs array $subject for str_replace()/str_ireplace() JIT/AOT (#23912).
 *
 * AOT locals are often {@see JITVariable::TYPE_VALUE}. The old fallback treated every
 * non-literal-string subject as an array, so {@see HashTableReadLlvm::ensureHashtablePointer}
 * overwrote string boxes with empty hashtables and echo printed "Array".
 *
 * php-src: ext/standard/string.c — php_str_replace_in_subject (Z_TYPE string vs array).
 */
final class JitStrReplaceSubject
{
    /** Compile-time known array $subject (hashtable / native array / array-init box). */
    public static function isKnownArray(JITVariable $arg): bool
    {
        if (JITVariable::TYPE_HASHTABLE === $arg->type) {
            return true;
        }
        if (0 !== ($arg->type & JITVariable::IS_NATIVE_ARRAY)) {
            return true;
        }

        return (bool) ($arg->valueBoxHashtable ?? false);
    }
}
