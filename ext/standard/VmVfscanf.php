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
    public static function parse(int $handle, string $format, array $outVars): int|false
    {
        $start = VmFs::ftell($handle);
        if (false === $start) {
            return false;
        }
        $data = VmFs::streamGetContents($handle, -1, -1);
        if (false === $data) {
            return false;
        }
        [$assigned, $consumed] = VmSscanf::parseWithConsumed($data, $format, $outVars);
        self::repositionStreamAfterScan($handle, $start, $data, $consumed);
        if (0 === $assigned && '' === $data) {
            return false;
        }

        return $assigned;
    }

    /** Two-arg fscanf()/vfscanf(): return parsed values as a list array (php-src ext/standard/fscanf.c, #9284). */
    public static function parseToArray(int $handle, string $format): HashTable|false
    {
        $start = VmFs::ftell($handle);
        if (false === $start) {
            return false;
        }
        $data = VmFs::streamGetContents($handle, -1, -1);
        if (false === $data) {
            return false;
        }
        $slots = VmSscanf::countConversionSpecs($format);
        if (0 === $slots) {
            return '' === $data ? false : new HashTable();
        }
        $temps = [];
        for ($i = 0; $i < $slots; ++$i) {
            $temps[] = new Variable();
        }
        [$assigned, $consumed] = VmSscanf::parseWithConsumed($data, $format, $temps);
        self::repositionStreamAfterScan($handle, $start, $data, $consumed);
        if (0 === $assigned && '' === $data) {
            return false;
        }
        $ht = new HashTable();
        for ($i = 0; $i < $slots; ++$i) {
            $copy = new Variable();
            if ($i < $assigned) {
                $copy->copyFrom($temps[$i]);
            } else {
                $copy->null();
            }
            $ht->append($copy);
        }

        return $ht;
    }

    /**
     * Advance stream position after scanf and set EOF when the scan consumed all remaining bytes.
     *
     * php-src ext/standard/scanf.c — feof() true after fscanf reads the last token (#11975).
     */
    private static function repositionStreamAfterScan(int $handle, int $start, string $data, int $consumed): void
    {
        if ($consumed <= 0) {
            return;
        }
        VmFs::fseek($handle, $start + $consumed, \SEEK_SET);
        if ($consumed >= \strlen($data)) {
            VmFs::fread($handle, 1);
        }
    }
}
