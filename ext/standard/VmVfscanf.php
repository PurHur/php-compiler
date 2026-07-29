<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * fscanf()/vfscanf() stream scanf helper.
 *
 * php-src ext/standard/file.c PHP_FUNCTION(fscanf) reads one line via
 * php_stream_get_line, then php_sscanf_internal — EOF (no line) returns false;
 * whitespace-only line with no assignment returns null in array mode (#24448).
 */
final class VmVfscanf
{
    /**
     * @param list<\PHPCompiler\VM\Variable> $outVars
     */
    public static function parse(int $handle, string $format, array $outVars): int|false
    {
        $scanned = self::readAndScan($handle, $format, $outVars);
        if (false === $scanned) {
            return false;
        }
        [$data, $assigned] = $scanned;
        if (0 === $assigned && '' === $data) {
            return false;
        }

        return $assigned;
    }

    /** Two-arg fscanf()/vfscanf(): return parsed values as a list array (php-src ext/standard/fscanf.c, #9284). */
    public static function parseToArray(int $handle, string $format): HashTable|false|null
    {
        $slots = VmSscanf::countConversionSpecs($format);
        if (0 === $slots) {
            $scanned = self::readAndScan($handle, $format, []);
            if (false === $scanned) {
                return false;
            }
            [$data, $assigned] = $scanned;
            if (0 === $assigned) {
                if ('' === $data) {
                    return false;
                }
                if ('' === \trim($data)) {
                    return null;
                }
            }

            return new HashTable();
        }
        $temps = [];
        for ($i = 0; $i < $slots; ++$i) {
            $temps[] = new Variable();
        }
        $scanned = self::readAndScan($handle, $format, $temps);
        if (false === $scanned) {
            return false;
        }
        [$data, $assigned] = $scanned;
        if (0 === $assigned) {
            if ('' === $data) {
                return false;
            }
            if ('' === \trim($data)) {
                return null;
            }
        }
        $ht = new HashTable();
        $stored = $scanned[2] ?? $assigned;
        for ($i = 0; $i < $slots; ++$i) {
            $copy = new Variable();
            if ($i < $stored) {
                $copy->copyFrom($temps[$i]);
            } else {
                $copy->null();
            }
            $ht->append($copy);
        }

        return $ht;
    }

    /**
     * Read one line (php_stream_get_line) and scan it — leftover on the line is discarded (#24448).
     *
     * @param list<\PHPCompiler\VM\Variable> $outVars
     *
     * @return array{0: string, 1: int, 2: int}|false
     */
    private static function readAndScan(int $handle, string $format, array $outVars): array|false
    {
        $line = VmFs::fgets($handle);
        if (false === $line) {
            return false;
        }
        [$assigned, , $stored] = VmSscanf::parseWithConsumed($line, $format, $outVars);

        return [$line, $assigned, $stored];
    }
}
