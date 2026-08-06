<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Thin-standalone NestedJIT json_encode encoder (#27020 / #26977 / #27078 / #27182, php-in-PHP).
 *
 * Context-free: no VmJson / runtime-vm. NestedJIT: $pair[0]/$pair[1] only.
 * Packed lists → `[…]` via isPackedList(); else `{…}` (#26977 Done-when).
 * Nested arrays: foreach on Variable locals — not encodeValue() mutual recursion and not
 * `$val->toArray()` for first-level pair values (#27074 / #27182).
 * Numeric type codes — NestedJIT mis-types Variable::TYPE_* class constants (#27075 / #27020).
 * No str_replace — NestedJIT helper emit lacks phpc_str_replace (#27078).
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
            return self::quote($value->toString());
        }
        // TYPE_ARRAY=6; kind 7 HT tags under NestedJIT / IS_REFCOUNTED (#26977).
        if (6 === $t || 7 === $t) {
            return self::encodeHashtable($value->toArray(), $flags);
        }

        // NestedJIT may tag string slots with non-4 type bytes; toInt → 0 (#27078).
        return self::quote($value->toString());
    }

    public static function encodeHashtable(HashTable $ht, int $flags): ?string
    {
        $packed = $ht->isPackedList();
        $out = $packed ? '[' : '{';
        $n = 0;
        foreach ($ht->exportKeyValuePairs(true) as $pair) {
            if ($n > 0) {
                $out .= ',';
            }
            if (!$packed) {
                $key = $pair[0];
                $kt = $key->type & 0x7f;
                if (1 === $kt) {
                    $out .= self::quote((string) $key->toInt()).':';
                } else {
                    $out .= self::quote($key->toString()).':';
                }
            }
            $val = $pair[1];
            $t = $val->type & 0x7f;
            if (6 === $t || 7 === $t) {
                // toArray + recurse — NestedJIT foreach on typed HT aborts under thin O=0
                // HashTableChunkLlvm (#27182). Helper-runtime often hits the else branch.
                $out .= self::encodeHashtable($val->toArray(), $flags) ?? 'null';
            } elseif (1 === $t) {
                $out .= (string) $val->toInt();
            } elseif (0 === $t) {
                $out .= 'null';
            } elseif (3 === $t) {
                $out .= $val->toBool() ? 'true' : 'false';
            } elseif (2 === $t) {
                $out .= (string) $val->toFloat();
            } elseif (4 === $t) {
                $out .= self::quote($val->toString());
            } else {
                // #27182: helper-runtime array_chunk nested HTs often lack type 6/7.
                // Value-foreach walks packed chunks (quote/toInt yielded "" / 0).
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
                    } else {
                        $inner .= (string) $elem->toInt();
                    }
                    ++$m;
                }
                $inner .= ']';
                $out .= $m > 0 ? $inner : (string) $val->toInt();
            }
            ++$n;
        }
        $out .= $packed ? ']' : '}';

        return $out;
    }

    /**
     * php-src json_escape_string subset (NestedJIT-safe — no str_replace).
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
        $out = '"';
        $n = \strlen($s);
        $i = 0;
        while ($i < $n) {
            $ch = $s[$i];
            if ('\\' === $ch) {
                $out .= '\\\\';
            } elseif ('"' === $ch) {
                $out .= '\\"';
            } elseif ('/' === $ch) {
                $out .= '\\/';
            } elseif ("\n" === $ch) {
                $out .= '\\n';
            } elseif ("\r" === $ch) {
                $out .= '\\r';
            } elseif ("\t" === $ch) {
                $out .= '\\t';
            } else {
                $out .= $ch;
            }
            ++$i;
        }

        return $out.'"';
    }
}
