<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * Thin-standalone NestedJIT serialize() for SplFixedArray (#33634 / #33639).
 *
 * Own TU with a single public method — NestedJIT mis-types extra methods in the same file (#27030).
 * Prefer helper-runtime cache (do not force PHP_COMPILER_HELPER_RUNTIME_O=0) — peer #32925 / #33625.
 *
 * Relies on HashTableExportKeyValuePairs including TYPE_NULL (skip TYPE_UNDEFINED only) so
 * SplFixedArray holes emit as i:k;N; (#33639 / php-src spl_fixedarray.c).
 */
final class SerializeSplFixedArrayNestedJitHelper
{
    /**
     * Full `O:len:"SplFixedArray":N:{i:0;…}`.
     *
     * @param mixed $className
     */
    public static function encodeWire($className, int $classLen, HashTable $storage): string
    {
        if (null === $className) {
            $className = '';
        } else {
            $className = $className.'';
        }
        if ($classLen < 0) {
            $classLen = 0;
        }
        $body = '';
        $n = 0;
        foreach ($storage->exportKeyValuePairs(true) as $pair) {
            $key = $pair[0];
            $kt = $key->type & 0x7f;
            if (1 === $kt) {
                $body .= 'i:'.((string) $key->toInt()).';';
            } else {
                $ks = $key->toString();
                if (null === $ks) {
                    $body .= 's:0:"";';
                } else {
                    $body .= 's:'.((string) \strlen($ks)).':"'.$ks.'";';
                }
            }
            $val = $pair[1];
            $t = $val->type & 0x7f;
            if (1 === $t) {
                $body .= 'i:'.((string) $val->toInt()).';';
            } elseif (0 === $t) {
                $body .= 'N;';
            } elseif (3 === $t) {
                $body .= $val->toBool() ? 'b:1;' : 'b:0;';
            } elseif (2 === $t) {
                $body .= 'd:'.((string) $val->toFloat()).';';
            } elseif (4 === $t) {
                $vs = $val->toString();
                if (null === $vs) {
                    $body .= 's:0:"";';
                } else {
                    $body .= 's:'.((string) \strlen($vs)).':"'.$vs.'";';
                }
            } else {
                $body .= 'N;';
            }
            ++$n;
        }

        return 'O:'.((string) $classLen).':"'.$className.'":'.((string) $n).':{'.$body.'}';
    }
}
