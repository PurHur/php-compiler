<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;

/**
 * Thin-standalone NestedJIT serialize() scalar encoder (#27030 / #34483).
 *
 * Array HT walk is {@see \PHPCompiler\JIT\SerializeHashtableLlvm} (peer JsonEncodeArrayLlvm
 * #26367) — NestedJIT `$pair[1]->toInt()` on exportKeyValuePairs SIGABRTs (#34483).
 * AOT HT export keeps JIT tags (#33520): NATIVE_BOOL=2, NATIVE_DOUBLE=3 (#33682).
 * php-src: ext/standard/var.c — php_var_serialize
 */
final class SerializeNestedJitHelper
{
    public static function encodeValue(Variable $value, int $flags): ?string
    {
        $t = $value->type & 0x7f;
        if (1 === $t) {
            return 'i:'.((string) $value->toInt()).';';
        }
        if (0 === $t) {
            return 'N;';
        }
        if (2 === $t) {
            // JIT TYPE_NATIVE_BOOL — if/else avoids NestedJIT i1 ternary stick (#33687 / #33682)
            if ($value->toBool()) {
                return 'b:1;';
            }

            return 'b:0;';
        }
        if (3 === $t) {
            // JIT TYPE_NATIVE_DOUBLE (#33682 / #33520)
            return 'd:'.((string) $value->toFloat()).';';
        }
        if (4 === $t) {
            return self::quote($value->toString());
        }
        // Arrays / kind-7 HT: __compiler_serialize_hashtable ABI (#34483).
        if (6 === $t || 7 === $t) {
            return 'a:0:{}';
        }

        return self::quote($value->toString());
    }

    /**
     * Serialize string wire `s:len:"…";` (length-prefixed — no escape).
     *
     * @param mixed $s NestedJIT toString may yield null
     */
    private static function quote($s): string
    {
        // Do not `$s.''` first — NestedJIT concat on hashtable-key toString() can
        // zero the length field while keeping a dangling value buffer (#32911).
        if (null === $s) {
            return 's:0:"";';
        }
        $n = \strlen($s);

        return 's:'.((string) $n).':"'.$s.'";';
    }
}
