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
 * AOT HT export keeps JIT tags (#33520): NATIVE_BOOL=2, NATIVE_DOUBLE=3 (#33682).
 * Float wire uses {@see IniGetLeafJitHelper::formatSerializeDouble} / PG(serialize_precision)
 * (#35027) — not `(string)` cast (PG(precision)).
 *
 * Non-empty array HT ABI is {@see \PHPCompiler\JIT\SerializeArrayLlvm} via
 * {@see \PHPCompiler\JIT\Builtin\StringSerialize} (#34483) — NestedJIT encodeHashtable
 * SIGABRTed on flat/assoc arrays; this helper remains for scalar encodeValue + fallback.
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
            // JIT TYPE_NATIVE_DOUBLE — PG(serialize_precision), not (string) cast (#35027)
            return 'd:'.IniGetLeafJitHelper::formatSerializeDouble($value->toFloat()).';';
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
        // NestedJIT foreach does not reliably mutate bare int counters ($n++ stays 0 /
        // aborts). Use a string latch for the element count (#31101 / peer JsonEncode).
        $body = '';
        $count = '0';
        $n = '0';
        foreach ($ht->exportKeyValuePairs(true) as $pair) {
            $key = $pair[0];
            $kt = $key->type & 0x7f;
            if (1 === $kt) {
                $body .= 'i:'.((string) $key->toInt()).';';
            } else {
                // NestedJIT: (string)$Variable yields s:0:"…" — use toString() like
                // JsonEncodeNestedJitHelper (#32911 / peer #27020).
                $body .= self::quote($key->toString());
            }
            $val = $pair[1];
            $t = $val->type & 0x7f;
            if (6 === $t || 7 === $t) {
                // toArray + recurse — NestedJIT foreach on typed HT aborts under thin
                // O=0 HashTableChunkLlvm (#27182 / JsonEncode). Prefer this over the
                // value-foreach nest that SIGABRTed flat arrays too (#34483).
                $nested = self::encodeHashtable($val->toArray(), $flags);
                if (null === $nested) {
                    $body .= 'N;';
                } else {
                    $body .= $nested;
                }
            } elseif (1 === $t) {
                $body .= 'i:'.((string) $val->toInt()).';';
            } elseif (0 === $t) {
                $body .= 'N;';
            } elseif (2 === $t) {
                if ($val->toBool()) {
                    $body .= 'b:1;';
                } else {
                    $body .= 'b:0;';
                }
            } elseif (3 === $t) {
                $body .= 'd:'.IniGetLeafJitHelper::formatSerializeDouble($val->toFloat()).';';
            } elseif (4 === $t) {
                $body .= self::quote($val->toString());
            } else {
                // #27182: helper-runtime nested HTs often lack type 6/7 — try toArray.
                $maybe = $val->toArray();
                if (null === $maybe) {
                    $body .= 'N;';
                } else {
                    $nested = self::encodeHashtable($maybe, $flags);
                    if (null === $nested) {
                        $body .= 'N;';
                    } else {
                        $body .= $nested;
                    }
                }
            }
            // String latch increment: '0'→'1'→'2'… via strlen of a digit run (#31101).
            $count .= '1';
            $n = (string) (\strlen($count) - 1);
        }

        return 'a:'.$n.':{'.$body.'}';
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
        $len = \strlen($s);

        return 's:'.((string) $len).':"'.$s.'";';
    }
}
