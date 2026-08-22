<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * Thin-standalone NestedJIT serialize() for SplDoublyLinkedList / SplQueue / SplStack (#33966).
 *
 * Own TU with a single public method — NestedJIT mis-types extra methods in the same file (#27030).
 * Prefer helper-runtime cache (do not force PHP_COMPILER_HELPER_RUNTIME_O=0) — peer #32925 / #33876.
 *
 * Wire: `O:len:"Class":3:{i:0;flags;i:1;a:N:{…}i:2;a:0:{}}` (php-src spl_dllist.c).
 * Default flags: DLL=0, SplQueue=IT_MODE_FIX(4), SplStack=IT_MODE_FIX|LIFO(6).
 * AOT HT export uses JIT tags (bool=2, double=3) — not VM float=2 / bool=3 (#33520).
 */
final class SerializeSplDllistNestedJitHelper
{
    /**
     * Full `O:len:"Class":3:{i:0;i:flags;i:1;a:…;i:2;a:0:{}}`.
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
        // php-src SplQueue/SplStack constructors set IT_MODE_FIX (+ LIFO for Stack).
        $flags = 0;
        if ('SplQueue' === $className) {
            $flags = 4;
        } elseif ('SplStack' === $className) {
            $flags = 6;
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
            } elseif (2 === $t) {
                if ($val->toBool()) {
                    $body .= 'b:1;';
                } else {
                    $body .= 'b:0;';
                }
            } elseif (3 === $t) {
                $body .= 'd:'.((string) $val->toFloat()).';';
            } elseif (4 === $t) {
                $vs = $val->toString();
                if (null === $vs) {
                    $body .= 's:0:"";';
                } else {
                    $body .= 's:'.((string) \strlen($vs)).':"'.$vs.'";';
                }
            } elseif (5 === $t) {
                $owire = \serialize($val);
                if (null === $owire || '' === $owire) {
                    $body .= 'N;';
                } else {
                    $body .= $owire;
                }
            } else {
                $body .= 'N;';
            }
            ++$n;
        }
        $storageWire = 'a:'.((string) $n).':{'.$body.'}';

        return 'O:'.((string) $classLen).':"'.$className.'":3:{i:0;i:'.((string) $flags)
            .';i:1;'.$storageWire.'i:2;a:0:{}}';
    }
}
