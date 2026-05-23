<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * CRC32B (IEEE) for crc32() — table-driven, matches PHP crc32() (issue #1014).
 */
final class VmCrc32
{
    /** @var list<int>|null */
    private static ?array $table = null;

    public static function compute(string $data, int $crc = 0): int
    {
        $state = ((int) $crc) ^ 0xFFFFFFFF;
        $len = VmString::byteLength($data);
        $table = self::table();
        for ($i = 0; $i < $len; ++$i) {
            $byte = \ord($data[$i]);
            $state = ($state >> 8) ^ $table[($state ^ $byte) & 0xFF];
        }

        return (int) ((~$state) & 0xFFFFFFFF);
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
                $c = ($c & 1) ? (0xEDB88320 ^ ($c >> 1)) : ($c >> 1);
            }
            $table[$i] = $c;
        }
        self::$table = $table;

        return $table;
    }
}
