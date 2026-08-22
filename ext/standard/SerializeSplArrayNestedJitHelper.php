<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * Thin-standalone NestedJIT serialize() for SPL ArrayObject family (#33625 / #33683).
 *
 * Own TU with a single public method — NestedJIT mis-types extra methods in the same file (#27030).
 * Prefer helper-runtime cache (do not force PHP_COMPILER_HELPER_RUNTIME_O=0) — peer #32925.
 *
 * Object bag values are TYPE_OBJECT (5) — must not fall through to foreach-as-array (SIGSEGV #33683).
 * php-src: ext/spl/spl_array.c — ArrayObject::__serialize integer-keyed bag.
 */
final class SerializeSplArrayNestedJitHelper
{
    /**
     * Full `O:len:"Class":4:{i:0;flags;i:1;storage;i:2;a:0:{}i:3;N;}`.
     *
     * @param mixed $className
     */
    public static function encodeWire($className, int $classLen, int $flags, HashTable $storage): string
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
            } elseif (5 === $t) {
                // TYPE_OBJECT — foreach-as-array SIGSEGVs (#33683).
                // NestedJIT cannot call ObjectEntry::propertiesWithNames (wrong receiver).
                // Delegate to serialize() so JitSerialize/LLVM encodes the object (php-src var.c).
                $owire = \serialize($val);
                if (null === $owire || '' === $owire) {
                    $body .= 'N;';
                } else {
                    $body .= $owire;
                }
            } else {
                // NestedJIT: $val->toArray() SIGABRTs — key=>value foreach (peer #32925).
                $inner = '';
                $in = 0;
                $had = '0';
                foreach ($val as $ik => $elem) {
                    $had = '1';
                    if (\is_int($ik)) {
                        $inner .= 'i:'.((string) $ik).';';
                    } elseif (\is_string($ik)) {
                        $inner .= 's:'.((string) \strlen($ik)).':"'.$ik.'";';
                    } elseif (\is_object($ik)) {
                        $ikt = $ik->type & 0x7f;
                        if (1 === $ikt) {
                            $inner .= 'i:'.((string) $ik->toInt()).';';
                        } else {
                            $iks = $ik->toString();
                            $inner .= null === $iks
                                ? 's:0:"";'
                                : 's:'.((string) \strlen($iks)).':"'.$iks.'";';
                        }
                    } else {
                        $inner .= 'i:'.((string) $in).';';
                    }
                    $et = $elem->type & 0x7f;
                    if (1 === $et) {
                        $inner .= 'i:'.((string) $elem->toInt()).';';
                    } elseif (0 === $et) {
                        $inner .= 'N;';
                    } elseif (3 === $et) {
                        $inner .= $elem->toBool() ? 'b:1;' : 'b:0;';
                    } elseif (4 === $et) {
                        $es = $elem->toString();
                        $inner .= null === $es
                            ? 's:0:"";'
                            : 's:'.((string) \strlen($es)).':"'.$es.'";';
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
                    $body .= 'a:0:{}';
                }
            }
            ++$n;
        }
        $storageWire = 'a:'.((string) $n).':{'.$body.'}';

        return 'O:'.((string) $classLen).':"'.$className.'":4:{i:0;i:'.((string) $flags)
            .';i:1;'.$storageWire.'i:2;a:0:{}i:3;N;}';
    }
}
