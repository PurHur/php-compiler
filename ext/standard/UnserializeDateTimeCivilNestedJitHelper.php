<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/** NestedJIT: civil Y-m-d H:i:s at offset → unix UTC (#34594). Tiny TU. */
final class UnserializeDateTimeCivilNestedJitHelper
{
    public static function utcEpochAt(string $payload, int $pos): int
    {
        $payload = $payload.'';
        $len = \strlen($payload);
        if ($pos < 0 || $pos + 19 > $len) {
            return 0;
        }
        $y = (\ord($payload[$pos]) - 48) * 1000
            + (\ord($payload[$pos + 1]) - 48) * 100
            + (\ord($payload[$pos + 2]) - 48) * 10
            + (\ord($payload[$pos + 3]) - 48);
        $mo = (\ord($payload[$pos + 5]) - 48) * 10 + (\ord($payload[$pos + 6]) - 48);
        $d = (\ord($payload[$pos + 8]) - 48) * 10 + (\ord($payload[$pos + 9]) - 48);
        $h = (\ord($payload[$pos + 11]) - 48) * 10 + (\ord($payload[$pos + 12]) - 48);
        $mi = (\ord($payload[$pos + 14]) - 48) * 10 + (\ord($payload[$pos + 15]) - 48);
        $s = (\ord($payload[$pos + 17]) - 48) * 10 + (\ord($payload[$pos + 18]) - 48);
        if ($y < 1970 || $mo < 1 || $mo > 12 || $d < 1 || $d > 31 || $h > 23 || $mi > 59 || $s > 60) {
            return 0;
        }
        $yy = $y - (int) ($mo <= 2);
        $era = (int) (($yy >= 0 ? $yy : $yy - 399) / 400);
        $yoe = $yy - $era * 400;
        $doy = (int) ((153 * ($mo + ($mo > 2 ? -3 : 9)) + 2) / 5) + $d - 1;
        $doe = $yoe * 365 + (int) ($yoe / 4) - (int) ($yoe / 100) + $doy;
        $days = $era * 146097 + $doe - 719468;

        return $days * 86400 + $h * 3600 + $mi * 60 + $s;
    }

    public static function microsecondAt(string $payload, int $pos): int
    {
        $payload = $payload.'';
        $len = \strlen($payload);
        if ($pos < 0 || $pos + 20 > $len || '.' !== $payload[$pos + 19]) {
            return 0;
        }
        $us = 0;
        $digits = 0;
        $j = $pos + 20;
        while ($j < $len && $digits < 6 && $payload[$j] >= '0' && $payload[$j] <= '9') {
            $us = $us * 10 + (\ord($payload[$j]) - 48);
            ++$j;
            ++$digits;
        }
        while ($digits < 6) {
            $us = $us * 10;
            ++$digits;
        }

        return $us;
    }
}
