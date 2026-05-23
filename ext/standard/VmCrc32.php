<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM crc32() — CRC32B (IEEE / zlib polynomial), signed 32-bit return (issue #1014).
 */
final class VmCrc32
{
    /** @var list<int>|null */
    private static ?array $table = null;

    public static function crc32(string $string, int $crc = 0): int
    {
        $state = ((int) $crc) ^ 0xFFFFFFFF;
        $len = strlen($string);
        for ($i = 0; $i < $len; ++$i) {
            $byte = ord($string[$i]);
            $state = (self::table()[$byte ^ ($state & 0xFF)] ^ (($state >> 8) & 0x00FFFFFF));
        }
        return ($state ^ 0xFFFFFFFF) & 0xFFFFFFFF;
    }

    /** @return list<int> */
    private static function table(): array
    {
        if (null !== self::$table) {
            return self::$table;
        }
        $table = [];
        for ($i = 0; $i < 256; ++$i) {
            $c = $i;
            for ($j = 0; $j < 8; ++$j) {
                $c = (0 !== ($c & 1)) ? (0xEDB88320 ^ ($c >> 1)) : ($c >> 1);
            }
            $table[$i] = $c & 0xFFFFFFFF;
        }
        self::$table = $table;

        return $table;
    }
}
