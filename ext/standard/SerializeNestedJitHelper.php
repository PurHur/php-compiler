<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Thin-standalone NestedJIT serialize() encoder (#27030, php-in-PHP).
 *
 * Context-free: no VmSerialize / runtime-vm (those SIGSEGV under thin AOT NestedJIT).
 * Mirrors {@see JsonEncodeNestedJitHelper} (#27020) structure closely — NestedJIT is
 * sensitive to helper shape (extra methods in the same TU have mis-typed $pair slots).
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
        if (3 === $t) {
            return $value->toBool() ? 'b:1;' : 'b:0;';
        }
        if (2 === $t) {
            return 'd:'.((string) $value->toFloat()).';';
        }
        if (4 === $t) {
            return self::quote($value->toString());
        }
        if (6 === $t || 7 === $t) {
            return self::encodeHashtable($value->toArray(), $flags);
        }

        return self::quote($value->toString());
    }

    public static function encodeHashtable(HashTable $ht, int $flags): ?string
    {
        $body = '';
        $n = 0;
        foreach ($ht->exportKeyValuePairs(true) as $pair) {
            $key = $pair[0];
            $kt = $key->type & 0x7f;
            if (1 === $kt) {
                $body .= 'i:'.((string) $key->toInt()).';';
            } else {
                $body .= self::quote((string) $key);
            }
            $val = $pair[1];
            $t = $val->type & 0x7f;
            if (6 === $t || 7 === $t) {
                $body .= self::encodeHashtable($val->toArray(), $flags) ?? 'N;';
            } elseif (1 === $t) {
                $body .= 'i:'.((string) $val->toInt()).';';
            } elseif (0 === $t) {
                $body .= 'N;';
            } elseif (3 === $t) {
                $body .= $val->toBool() ? 'b:1;' : 'b:0;';
            } elseif (2 === $t) {
                $body .= 'd:'.((string) $val->toFloat()).';';
            } elseif (4 === $t) {
                $body .= self::quote($val->toString());
            } else {
                // Peer #27182: helper-runtime / NestedJIT may lack type 6/7 on values.
                $body .= 'i:'.((string) $val->toInt()).';';
            }
            ++$n;
        }

        return 'a:'.((string) $n).':{'.$body.'}';
    }

    /**
     * Serialize string wire `s:len:"…";` (length-prefixed — no escape).
     *
     * @param mixed $s NestedJIT toString may yield null
     */
    private static function quote($s): string
    {
        if (null === $s) {
            $s = '';
        } else {
            $s = $s.'';
        }
        $n = \strlen($s);

        return 's:'.((string) $n).':"'.$s.'";';
    }
}
