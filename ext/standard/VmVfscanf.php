<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * vfscanf() stream scanf helper (php-src ext/standard/scanf.c; issue #6174).
 */
final class VmVfscanf
{
    /**
     * @param list<\PHPCompiler\VM\Variable> $outVars
     */
    public static function parse(int $handle, string $format, array $outVars): int
    {
        $start = VmFs::ftell($handle);
        if (false === $start) {
            return 0;
        }
        $data = VmFs::streamGetContents($handle, -1, -1);
        if (false === $data) {
            return 0;
        }
        [$assigned, $consumed] = VmSscanf::parseWithConsumed($data, $format, $outVars);
        if ($consumed > 0) {
            VmFs::fseek($handle, $start + $consumed, \SEEK_SET);
        }

        return $assigned;
    }

    /** Two-arg fscanf()/vfscanf(): return parsed values as a list array (php-src ext/standard/fscanf.c, #9284). */
    public static function parseToArray(int $handle, string $format): HashTable
    {
        $start = VmFs::ftell($handle);
        if (false === $start) {
            return new HashTable();
        }
        $data = VmFs::streamGetContents($handle, -1, -1);
        if (false === $data) {
            return new HashTable();
        }
        $slots = VmSscanf::countConversionSpecs($format);
        if (0 === $slots) {
            return new HashTable();
        }
        $temps = [];
        for ($i = 0; $i < $slots; ++$i) {
            $temps[] = new Variable();
        }
        [$assigned, $consumed] = VmSscanf::parseWithConsumed($data, $format, $temps);
        if ($consumed > 0) {
            VmFs::fseek($handle, $start + $consumed, \SEEK_SET);
        }
        $ht = new HashTable();
        for ($i = 0; $i < $assigned; ++$i) {
            $copy = new Variable();
            $copy->copyFrom($temps[$i]);
            $ht->append($copy);
        }

        return $ht;
    }
}
