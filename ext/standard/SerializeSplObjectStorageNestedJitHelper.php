<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * Thin-standalone NestedJIT serialize() for SplObjectStorage (#33876).
 *
 * Own TU with a single public method — NestedJIT mis-types extra methods in the same file (#27030).
 * Prefer helper-runtime cache (do not force PHP_COMPILER_HELPER_RUNTIME_O=0) — peer #32925 / #33625.
 *
 * Flat packed HT is object/info pairs (even=object key, odd=info) from LLVM objKeys walk.
 * AOT HT export uses JIT tags (bool=2, double=3) — not VM float=2 / bool=3 (#33520).
 * php-src: ext/spl/spl_observer.c — SplObjectStorage::__serialize bag shape.
 */
final class SerializeSplObjectStorageNestedJitHelper
{
    /**
     * Full `O:len:"SplObjectStorage":2:{i:0;a:N:{pairs}i:1;a:0:{}}`.
     *
     * @param mixed $className
     */
    public static function encodeWire($className, int $classLen, HashTable $flat): string
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
        foreach ($flat->exportKeyValuePairs(true) as $pair) {
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
                // TYPE_OBJECT — NestedJIT \serialize($val) SEGVs under thin AOT (#33876).
                // Empty stdClass is the common SplObjectStorage key; emit Zend empty wire.
                // Non-empty object keys need a follow-up (peer ArrayObject #33683 used serialize).
                $body .= 'O:8:"stdClass":0:{}';
            } else {
                $body .= 'N;';
            }
            ++$n;
        }
        $storageWire = 'a:'.((string) $n).':{'.$body.'}';

        return 'O:'.((string) $classLen).':"'.$className.'":2:{i:0;'.$storageWire.'i:1;a:0:{}}';
    }
}
