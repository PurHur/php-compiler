<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Thin-standalone NestedJIT json_encode encoder (#27020, php-in-PHP).
 *
 * Context-free: no VmJson / runtime-vm. NestedJIT: $pair[0]/$pair[1] only.
 * Packed lists encode as `[…]` (was always `{…}` — broke AOT array_splice/sort, #27075).
 * php-src: ext/json/php_json.c — php_json_encode
 */
final class JsonEncodeNestedJitHelper
{
    public static function encodeValue(Variable $value, int $flags): ?string
    {
        $t = $value->type & 0x7f;
        if (1 === $t) {
            return (string) $value->toInt();
        }
        if (0 === $t) {
            return 'null';
        }
        if (3 === $t) {
            return $value->toBool() ? 'true' : 'false';
        }
        if (2 === $t) {
            return (string) $value->toFloat();
        }
        if (4 === $t) {
            return '"'.$value->toString().'"';
        }
        if (7 === $t) {
            return self::encodeHashtable($value->toArray(), $flags);
        }

        return (string) $value->toInt();
    }

    public static function encodeHashtable(HashTable $ht, int $flags): ?string
    {
        // Single-pass NestedJIT-safe list form. Prior stub always emitted `{"":…}` because
        // int-key toString() lowered empty under NestedJIT (#27075 / #27020).
        // Inline nested-array handling here — do not call encodeValue() (mutual NestedJIT
        // recursion with encodeHashtable aborts under thin AOT, #27074).
        $out = '[';
        $n = 0;
        foreach ($ht->exportKeyValuePairs(true) as $pair) {
            if ($n > 0) {
                $out .= ',';
            }
            $val = $pair[1];
            $t = $val->type & 0x7f;
            if (6 === $t || 7 === $t) {
                // Nested array: encode via foreach (NestedJIT-safe on Variable locals).
                // Avoid encodeValue↔encodeHashtable mutual recursion and toArray() (#27074).
                $inner = '[';
                $m = 0;
                foreach ($val as $elem) {
                    if ($m > 0) {
                        $inner .= ',';
                    }
                    $et = $elem->type & 0x7f;
                    if (1 === $et) {
                        $inner .= (string) $elem->toInt();
                    } elseif (0 === $et) {
                        $inner .= 'null';
                    } elseif (6 === $et || 7 === $et) {
                        $inner .= self::encodeHashtable($elem->toArray(), $flags) ?? 'null';
                    } else {
                        $inner .= (string) $elem->toInt();
                    }
                    ++$m;
                }
                $inner .= ']';
                $out .= $inner;
            } elseif (1 === $t) {
                $out .= (string) $val->toInt();
            } elseif (0 === $t) {
                $out .= 'null';
            } else {
                $out .= (string) $val->toInt();
            }
            ++$n;
        }
        $out .= ']';

        return $out;
    }
}
