<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Slim ini_get/ini_set/ini_restore leaf for thin AOT (#33059).
 *
 * NestedJIT of {@see IniJitHelper} under thin AOT BSS-zeros statics and collapses
 * returns to string "0". This leaf keeps mutable state in a few statics and seeds
 * them on first use (assignments work; property defaults do not).
 *
 * Avoid {@see strtolower} — NestedJIT miscompiles it on option strings here.
 * php-src: ext/standard/basic_functions.stub.php — PHP_FUNCTION(ini_get)
 */
final class IniGetLeafJitHelper
{
    private static bool $seeded = false;

    private static int $precision = 0;

    private static int $serializePrecision = 0;

    private static bool $exceptionIgnoreArgs = false;

    public static function iniGet(string $option): ?string
    {
        self::seed();
        if ('precision' === $option || 'PRECISION' === $option) {
            return self::formatInt(self::$precision);
        }
        if ('serialize_precision' === $option) {
            return self::formatInt(self::$serializePrecision);
        }
        if ('memory_limit' === $option) {
            return '-1';
        }
        if ('display_errors' === $option) {
            return '';
        }
        if ('max_execution_time' === $option) {
            return '0';
        }
        if ('default_charset' === $option) {
            return 'UTF-8';
        }
        if ('pcre.jit' === $option) {
            return '1';
        }
        if ('session.use_strict_mode' === $option) {
            return '0';
        }
        if ('session.use_cookies' === $option || 'SESSION.USE_COOKIES' === $option) {
            return '1';
        }
        if ('session.use_only_cookies' === $option || 'SESSION.USE_ONLY_COOKIES' === $option) {
            return '1';
        }
        if ('session.save_handler' === $option || 'SESSION.SAVE_HANDLER' === $option) {
            return 'files';
        }
        if ('zend.exception_ignore_args' === $option) {
            return self::$exceptionIgnoreArgs ? '1' : '0';
        }

        $mirrored = VmIniIntrospection::mirroredHostIniGet($option);
        if (null !== $mirrored) {
            return $mirrored;
        }

        return null;
    }

    public static function iniSet(string $option, string $newValue): ?string
    {
        self::seed();
        if ('precision' === $option || 'PRECISION' === $option) {
            $old = self::formatInt(self::$precision);
            self::$precision = self::parseIntIni($newValue, 14);
            VmIni::syncPrecision(self::$precision);

            return $old;
        }
        if ('serialize_precision' === $option) {
            $old = self::formatInt(self::$serializePrecision);
            self::$serializePrecision = self::parseIntIni($newValue, -1);
            VmIni::syncSerializePrecision(self::$serializePrecision);

            return $old;
        }
        if ('zend.exception_ignore_args' === $option) {
            $old = self::$exceptionIgnoreArgs ? '1' : '0';
            $falsy = ('' === $newValue || '0' === $newValue || 'off' === $newValue || 'false' === $newValue
                || 'Off' === $newValue || 'False' === $newValue);
            self::$exceptionIgnoreArgs = !$falsy;

            return $old;
        }

        return null;
    }

    public static function iniRestore(string $option): void
    {
        self::seed();
        if ('precision' === $option || 'PRECISION' === $option) {
            self::$precision = 14;
            VmIni::syncPrecision(14);

            return;
        }
        if ('serialize_precision' === $option) {
            self::$serializePrecision = -1;
            VmIni::syncSerializePrecision(-1);

            return;
        }
        if ('zend.exception_ignore_args' === $option) {
            self::$exceptionIgnoreArgs = false;
        }
    }

    private static function seed(): void
    {
        if (self::$seeded) {
            return;
        }
        self::$seeded = true;
        self::$precision = 14;
        self::$serializePrecision = -1;
        self::$exceptionIgnoreArgs = false;
    }

    /**
     * NestedJIT-safe int→decimal (no sprintf / (string) cast — #33059 / #35020).
     * Hot literals first; digit walk for the rest (was wrongly falling back to "14").
     */
    private static function formatInt(int $n): string
    {
        if (14 === $n) {
            return '14';
        }
        if (8 === $n) {
            return '8';
        }
        if (-1 === $n) {
            return '-1';
        }
        if (0 === $n) {
            return '0';
        }
        if (10 === $n) {
            return '10';
        }
        if (17 === $n) {
            return '17';
        }
        if (15 === $n) {
            return '15';
        }
        if (2 === $n) {
            return '2';
        }
        if (4 === $n) {
            return '4';
        }
        if (6 === $n) {
            return '6';
        }
        if (12 === $n) {
            return '12';
        }
        if (16 === $n) {
            return '16';
        }

        return self::intToDecimalDigits($n);
    }

    /** php-src PG(precision) for float→string LLVM global sync (#21963 / #33059). */
    public static function getPrecisionInt(): int
    {
        self::seed();

        return self::$precision;
    }

    /** php-src PG(serialize_precision) for var_dump LLVM global sync (#32328 / #33059). */
    public static function getSerializePrecisionInt(): int
    {
        self::seed();

        return self::$serializePrecision;
    }

    /**
     * NestedJIT-safe parse of ini int strings (#33059 / #35020).
     *
     * Hot equality paths first (NestedJIT historically miscompiled general intval).
     * Digits beyond the whitelist walk char-by-char — previously "17" fell through to
     * $default so ini_set('serialize_precision','17') was a silent no-op under thin AOT.
     */
    private static function parseIntIni(string $raw, int $default): int
    {
        if ('14' === $raw) {
            return 14;
        }
        if ('8' === $raw) {
            return 8;
        }
        if ('-1' === $raw) {
            return -1;
        }
        if ('0' === $raw) {
            return 0;
        }
        if ('10' === $raw) {
            return 10;
        }
        if ('17' === $raw) {
            return 17;
        }
        if ('16' === $raw) {
            return 16;
        }
        if ('1' === $raw) {
            return 1;
        }
        if ('2' === $raw) {
            return 2;
        }
        if ('4' === $raw) {
            return 4;
        }
        if ('6' === $raw) {
            return 6;
        }
        if ('12' === $raw) {
            return 12;
        }
        if ('20' === $raw) {
            return 20;
        }
        // Hot serialize_precision values NestedJIT digit-walk has missed (#35027).
        if ('15' === $raw) {
            return 15;
        }
        if ('3' === $raw) {
            return 3;
        }
        if ('5' === $raw) {
            return 5;
        }
        if ('7' === $raw) {
            return 7;
        }
        if ('9' === $raw) {
            return 9;
        }
        if ('11' === $raw) {
            return 11;
        }
        if ('13' === $raw) {
            return 13;
        }
        if ('18' === $raw) {
            return 18;
        }
        if ('19' === $raw) {
            return 19;
        }

        return self::parseIntDigits($raw, $default);
    }

    /**
     * php-src zend_gcvt / PG(serialize_precision) for NestedJIT json_encode + serialize (#35027).
     *
     * JsonEncodeNestedJitHelper / SerializeNestedJitHelper must not use `(string)` float
     * (that follows PG(precision), not serialize_precision).
     */
    public static function formatSerializeDouble(float $num): string
    {
        self::seed();

        return VmSerializeFormat::formatDoubleWithPrecision($num, self::$serializePrecision);
    }

    /** Same as {@see formatSerializeDouble} with JSON lowercase exponent (#25111 / #23545). */
    public static function formatJsonDouble(float $num): string
    {
        $text = self::formatSerializeDouble($num);
        // NestedJIT: no str_replace — walk chars (#27078).
        $out = '';
        $len = \strlen($text);
        $i = 0;
        while ($i < $len) {
            $ch = $text[$i];
            if ('E' === $ch) {
                $out .= 'e';
            } else {
                $out .= $ch;
            }
            $i = $i + 1;
        }

        return $out;
    }

    /** Char-walk signed decimal; NestedJIT-safe (no intval / (int) cast). */
    private static function parseIntDigits(string $raw, int $default): int
    {
        $len = \strlen($raw);
        if (0 === $len) {
            return $default;
        }
        $i = 0;
        $neg = false;
        if ('-' === $raw[0]) {
            $neg = true;
            $i = 1;
            if ($i === $len) {
                return $default;
            }
        }
        $n = 0;
        $any = false;
        while ($i < $len) {
            $ch = $raw[$i];
            $digit = self::decimalDigit($ch);
            if ($digit < 0) {
                return $default;
            }
            $n = $n * 10 + $digit;
            $any = true;
            $i = $i + 1;
        }
        if (!$any) {
            return $default;
        }

        return $neg ? -$n : $n;
    }

    private static function decimalDigit(string $ch): int
    {
        if ('0' === $ch) {
            return 0;
        }
        if ('1' === $ch) {
            return 1;
        }
        if ('2' === $ch) {
            return 2;
        }
        if ('3' === $ch) {
            return 3;
        }
        if ('4' === $ch) {
            return 4;
        }
        if ('5' === $ch) {
            return 5;
        }
        if ('6' === $ch) {
            return 6;
        }
        if ('7' === $ch) {
            return 7;
        }
        if ('8' === $ch) {
            return 8;
        }
        if ('9' === $ch) {
            return 9;
        }

        return -1;
    }

    private static function intToDecimalDigits(int $n): string
    {
        if (0 === $n) {
            return '0';
        }
        $neg = $n < 0;
        if ($neg) {
            $n = -$n;
        }
        $out = '';
        while ($n > 0) {
            $digit = $n % 10;
            $out = self::digitChar($digit).$out;
            $n = intdiv($n, 10);
        }

        return $neg ? '-'.$out : $out;
    }

    private static function digitChar(int $digit): string
    {
        if (0 === $digit) {
            return '0';
        }
        if (1 === $digit) {
            return '1';
        }
        if (2 === $digit) {
            return '2';
        }
        if (3 === $digit) {
            return '3';
        }
        if (4 === $digit) {
            return '4';
        }
        if (5 === $digit) {
            return '5';
        }
        if (6 === $digit) {
            return '6';
        }
        if (7 === $digit) {
            return '7';
        }
        if (8 === $digit) {
            return '8';
        }

        return '9';
    }
}
