<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * str_getcsv()/fgetcsv() CSV parse for compiled JIT/AOT modules (#9444, php-in-PHP).
 *
 * SSOT: {@see VmCsv::parseLine()}; VM path uses {@see VmFs::csvRowToArray()}.
 * php-src: ext/standard/file.c — php_fgetcsv, str_getcsv
 */
final class CsvJitHelper
{
    /**
     * @return HashTable parsed row (never null; empty input yields [null] per VmCsv)
     */
    public static function strGetcsvArgv(
        string $input,
        string $separator,
        string $enclosure,
        string $escape,
    ): HashTable {
        return self::rowToHashTable(VmCsv::parseLine($input, $separator, $enclosure, $escape));
    }

    /**
     * @return HashTable parsed row from one line of bytes (fgetcsv after fgets)
     */
    public static function parseLineArgv(
        string $line,
        string $separator,
        string $enclosure,
        string $escape,
    ): HashTable {
        return self::rowToHashTable(VmCsv::parseLine($line, $separator, $enclosure, $escape));
    }

    /**
     * @param list<string|null> $fields
     */
    private static function rowToHashTable(array $fields): HashTable
    {
        $ht = new HashTable();
        foreach ($fields as $field) {
            $value = new Variable();
            if (null === $field) {
                $value->null();
            } else {
                $value->string($field);
            }
            $ht->append($value);
        }

        return $ht;
    }
}
