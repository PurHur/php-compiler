<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Thin-standalone NestedJIT wire for ArrayObject/ArrayIterator::__serialize bag (#33625).
 *
 * php-src: ext/spl/spl_array.c — ArrayObject::__serialize returns
 * [flags, storage, members, iteratorClass]. Storage is pre-serialized via
 * {@see __compiler_serialize_hashtable} (handles empty `a:0:{}`); members and
 * default iteratorClass are `a:0:{}` / `N;` for the thin-AOT surface.
 */
final class SerializeSplArrayObjectNestedJitHelper
{
    /**
     * @param mixed $className content used; length from $classLen (LLVM)
     * @param mixed $storageWire already `a:N:{…}` from hashtable serialize
     */
    public static function formatBag($className, int $classLen, int $flags, $storageWire): string
    {
        if (null === $className) {
            $className = '';
        } else {
            $className = $className.'';
        }
        if ($classLen < 0) {
            $classLen = 0;
        }
        if (null === $storageWire) {
            $storageWire = 'a:0:{}';
        } else {
            $storageWire = $storageWire.'';
        }

        return 'O:'.((string) $classLen).':"'.$className.'":4:{i:0;i:'
            .((string) $flags).';i:1;'.$storageWire.'i:2;a:0:{}i:3;N;}';
    }
}
