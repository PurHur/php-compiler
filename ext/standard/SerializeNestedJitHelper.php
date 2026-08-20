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
            // Top-level floats use ZendDoubleStringRuntime::formatSerializeWire (#31963).
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
                // Peer StrtrArrayJitHelper (#27056): (string) $pair[0] — not toString()
                // (NestedJIT HT keys: toString() → "" ; (string) embeds content) (#32911).
                $body .= self::quote((string) $key);
            }
            $val = $pair[1];
            $t = $val->type & 0x7f;
            if (6 === $t || 7 === $t) {
                // NestedJIT: $val->toArray() / exportKeyValuePairs on pair values SIGABRT
                // (#27031 / #32911). Peer JsonEncodeNestedJitHelper #27182: value-foreach
                // packed chunks. Assoc nested string keys still need a follow-up.
                $inner = '';
                $in = 0;
                $had = '0';
                foreach ($val as $elem) {
                    $had = '1';
                    $inner .= 'i:'.((string) $in).';';
                    $et = $elem->type & 0x7f;
                    if (1 === $et) {
                        $inner .= 'i:'.((string) $elem->toInt()).';';
                    } elseif (0 === $et) {
                        $inner .= 'N;';
                    } elseif (3 === $et) {
                        $inner .= $elem->toBool() ? 'b:1;' : 'b:0;';
                    } elseif (4 === $et) {
                        $inner .= self::quote($elem->toString());
                    } elseif (2 === $et) {
                        $inner .= 'd:'.((string) $elem->toFloat()).';';
                    } else {
                        $inner .= 'i:'.((string) $elem->toInt()).';';
                    }
                    ++$in;
                }
                if ('1' === $had) {
                    $body .= 'a:'.((string) $in).':{'.$inner.'}';
                } else {
                    $body .= 'N;';
                }
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
                // #27182: NestedJIT nested HTs often lack type 6/7 — value-foreach.
                $inner = '';
                $in = 0;
                $had = '0';
                foreach ($val as $elem) {
                    $had = '1';
                    $inner .= 'i:'.((string) $in).';';
                    $et = $elem->type & 0x7f;
                    if (1 === $et) {
                        $inner .= 'i:'.((string) $elem->toInt()).';';
                    } elseif (0 === $et) {
                        $inner .= 'N;';
                    } elseif (3 === $et) {
                        $inner .= $elem->toBool() ? 'b:1;' : 'b:0;';
                    } elseif (4 === $et) {
                        $inner .= self::quote($elem->toString());
                    } else {
                        $inner .= 'i:'.((string) $elem->toInt()).';';
                    }
                    ++$in;
                }
                if ('1' === $had) {
                    $body .= 'a:'.((string) $in).':{'.$inner.'}';
                } else {
                    $body .= 'N;';
                }
            }
            ++$n;
        }

        return 'a:'.((string) $n).':{'.$body.'}';
    }

    /**
     * Serialize string wire `s:len:"…";` (length-prefixed — no escape).
     *
     * NestedJIT: `\strlen` on HT-key casts is 0 while content still embeds in concat
     * (#21900 / #32911). Count with isset on a twin string — indexing the wire
     * payload itself can clear it for later concat (peer StrtrArrayJitHelper #27056).
     *
     * @param mixed $s NestedJIT toString may yield null
     */
    private static function quote($s): string
    {
        if (null === $s) {
            $s = '';
        }
        $walk = $s.'';
        $n = 0;
        while (isset($walk[$n])) {
            ++$n;
        }

        return 's:'.((string) $n).':"'.$s.'";';
    }
}
