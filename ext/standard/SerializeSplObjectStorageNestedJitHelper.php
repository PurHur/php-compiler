<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * Thin-standalone NestedJIT serialize() for SplObjectStorage (#33876).
 *
 * Own TU / single public method — NestedJIT mis-types extras in the same file (#27030).
 * Caller passes a packed flat HT of [object, info, object, info, …] built from `__objkey_node`
 * (exportKeyValuePairs does not walk objKeys).
 *
 * Wire: `O:len:"SplObjectStorage":2:{i:0;a:N:{…pairs…}i:1;a:0:{}}`
 * php-src: ext/spl/spl_observer.c — spl_object_storage_serialize / __serialize
 */
final class SerializeSplObjectStorageNestedJitHelper
{
    /**
     * Full SplObjectStorage O: wire from flat pair list.
     *
     * @param mixed $className
     */
    public static function encodeWire($className, int $classLen, HashTable $flatPairs): string
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
        foreach ($flatPairs->exportKeyValuePairs(true) as $pair) {
            $val = $pair[1];
            $t = $val->type & 0x7f;
            $body .= 'i:'.((string) $n).';';
            if (5 === $t) {
                // TYPE_OBJECT — NestedJIT cannot walk ObjectEntry; delegate to serialize().
                $owire = \serialize($val);
                if (null === $owire || '' === $owire) {
                    $body .= 'N;';
                } else {
                    $body .= $owire;
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

        return 'O:'.((string) $classLen).':"'.$className.'":2:{i:0;a:'.((string) $n).':{'.$body.'}i:1;a:0:{}}';
    }
}
