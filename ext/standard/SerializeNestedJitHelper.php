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
                // NestedJIT: (string)$Variable yields s:0:"…" — use toString() like
                // JsonEncodeNestedJitHelper (#32911 / peer #27020).
                $body .= self::quote($key->toString());
            }
            $val = $pair[1];
            $t = $val->type & 0x7f;
            if (6 === $t || 7 === $t) {
                // NestedJIT: $val->toArray() SIGABRTs on pair values (#27031 / #32925).
                // Key=>value foreach preserves assoc string keys (#32927); packed stays i:N.
                $inner = '';
                $in = 0;
                $had = '0';
                foreach ($val as $ik => $elem) {
                    $had = '1';
                    if (\is_int($ik)) {
                        $inner .= 'i:'.((string) $ik).';';
                    } elseif (\is_string($ik)) {
                        $inner .= self::quote($ik);
                    } elseif (\is_object($ik)) {
                        $ikt = $ik->type & 0x7f;
                        if (1 === $ikt) {
                            $inner .= 'i:'.((string) $ik->toInt()).';';
                        } else {
                            $inner .= self::quote($ik->toString());
                        }
                    } else {
                        $inner .= 'i:'.((string) $in).';';
                    }
                    $et = $elem->type & 0x7f;
                    if (1 === $et) {
                        $inner .= 'i:'.((string) $elem->toInt()).';';
                    } elseif (0 === $et) {
                        $inner .= 'N;';
                    } elseif (2 === $et) {
                        if ($elem->toBool()) {
                            $inner .= 'b:1;';
                        } else {
                            $inner .= 'b:0;';
                        }
                    } elseif (4 === $et) {
                        $inner .= self::quote($elem->toString());
                    } elseif (3 === $et) {
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
            } elseif (2 === $t) {
                if ($val->toBool()) {
                    $body .= 'b:1;';
                } else {
                    $body .= 'b:0;';
                }
            } elseif (3 === $t) {
                $body .= 'd:'.((string) $val->toFloat()).';';
            } elseif (4 === $t) {
                $body .= self::quote($val->toString());
            } else {
                // #27182: NestedJIT nested HTs often lack type 6/7 — same key=>value walk.
                $inner = '';
                $in = 0;
                $had = '0';
                foreach ($val as $ik => $elem) {
                    $had = '1';
                    if (\is_int($ik)) {
                        $inner .= 'i:'.((string) $ik).';';
                    } elseif (\is_string($ik)) {
                        $inner .= self::quote($ik);
                    } elseif (\is_object($ik)) {
                        $ikt = $ik->type & 0x7f;
                        if (1 === $ikt) {
                            $inner .= 'i:'.((string) $ik->toInt()).';';
                        } else {
                            $inner .= self::quote($ik->toString());
                        }
                    } else {
                        $inner .= 'i:'.((string) $in).';';
                    }
                    $et = $elem->type & 0x7f;
                    if (1 === $et) {
                        $inner .= 'i:'.((string) $elem->toInt()).';';
                    } elseif (0 === $et) {
                        $inner .= 'N;';
                    } elseif (2 === $et) {
                        if ($elem->toBool()) {
                            $inner .= 'b:1;';
                        } else {
                            $inner .= 'b:0;';
                        }
                    } elseif (4 === $et) {
                        $inner .= self::quote($elem->toString());
                    } elseif (3 === $et) {
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
        // Do not `$s.''` first — NestedJIT concat on hashtable-key toString() can
        // zero the length field while keeping a dangling value buffer (#32911).
        if (null === $s) {
            return 's:0:"";';
        }
        $n = \strlen($s);

        return 's:'.((string) $n).':"'.$s.'";';
    }
}
