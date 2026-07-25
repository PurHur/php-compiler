<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

/**
 * Thin libicui18n FFI for udat_open / udat_format — calendar-keyword locales (#22877).
 *
 * php-src: ext/intl/dateformat/dateformat_create.cpp (udat_open) + dateformat_format.c.
 * Used when IntlDateFormatter locale carries {@code @calendar=} and calendar is TRADITIONAL
 * so hebrew/islamic/japanese/buddhist field values match Zend/ICU.
 */
final class IcuDateFormat
{
    private static ?\FFI $ffi = null;
    private static string $symSuffix = '';
    private static bool $ffiUnavailable = false;

    /**
     * Format millis-since-epoch with ICU date styles for a locale (may include @calendar=).
     *
     * Style constants match UDateFormatStyle / IntlDateFormatter (NONE=-1 … SHORT=3).
     * Argument order matches php-src: udat_open(timeStyle, dateStyle, …).
     *
     * @return string|null null when ICU FFI is unavailable or udat_* fails
     */
    public static function formatStyles(
        string $locale,
        int $dateStyle,
        int $timeStyle,
        string $timezone,
        float $millis
    ): ?string {
        $fmt = self::open($locale, $dateStyle, $timeStyle, $timezone, null);
        if (null === $fmt) {
            return null;
        }
        try {
            return self::formatMillis($fmt[0], $millis);
        } finally {
            self::close($fmt[0]);
        }
    }

    /**
     * ICU pattern string for style+locale (udat_toPattern), or null.
     */
    public static function patternFromStyles(
        string $locale,
        int $dateStyle,
        int $timeStyle,
        string $timezone
    ): ?string {
        $fmt = self::open($locale, $dateStyle, $timeStyle, $timezone, null);
        if (null === $fmt) {
            return null;
        }
        try {
            return self::toPattern($fmt[0]);
        } finally {
            self::close($fmt[0]);
        }
    }

    public static function localeHasCalendarKeyword(string $locale): bool
    {
        return false !== stripos($locale, '@calendar=')
            || false !== stripos($locale, '-u-ca-');
    }

    /** @return array{0: object}|null */
    private static function open(
        string $locale,
        int $dateStyle,
        int $timeStyle,
        string $timezone,
        ?string $pattern
    ): ?array {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $suffix = self::$symSuffix;
        $open = 'udat_open'.$suffix;
        try {
            $status = \FFI::new('int32_t');
            $status->cdata = 0;
            $tzPtr = null;
            $tzLen = -1;
            if ('' !== $timezone) {
                $tz = self::utf8ToUChar($timezone);
                if (null === $tz) {
                    return null;
                }
                $tzPtr = $tz[0];
                $tzLen = $tz[1];
            }
            $patPtr = null;
            $patLen = -1;
            if (null !== $pattern && '' !== $pattern) {
                $u = self::utf8ToUChar($pattern);
                if (null === $u) {
                    return null;
                }
                $patPtr = $u[0];
                $patLen = $u[1];
            }
            // php-src dateformat_create.cpp: udat_open(timeStyle, dateStyle, locale, …)
            $fmt = $ffi->$open(
                $timeStyle,
                $dateStyle,
                $locale,
                $tzPtr,
                $tzLen,
                $patPtr,
                $patLen,
                \FFI::addr($status)
            );
            if (null === $fmt || $status->cdata > 0) {
                return null;
            }

            return [$fmt];
        } catch (\Throwable) {
            return null;
        }
    }

    private static function formatMillis(object $fmt, float $millis): ?string
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $suffix = self::$symSuffix;
        $format = 'udat_format'.$suffix;
        $toUtf8 = 'u_strToUTF8'.$suffix;
        try {
            $status = \FFI::new('int32_t');
            $status->cdata = 0;
            $needed = (int) $ffi->$format($fmt, $millis, null, 0, null, \FFI::addr($status));
            // U_BUFFER_OVERFLOW_ERROR (-124) expected on size probe.
            $status->cdata = 0;
            $cap = max(1, $needed + 1);
            $buf = \FFI::new('uint16_t['.$cap.']');
            $n = (int) $ffi->$format($fmt, $millis, $buf, $cap, null, \FFI::addr($status));
            if ($status->cdata > 0 || $n < 0) {
                return null;
            }
            $utf8len = \FFI::new('int32_t');
            $byteCap = max(64, $n * 3 + 8);
            $cbuf = \FFI::new('char['.$byteCap.']');
            $st2 = \FFI::new('int32_t');
            $st2->cdata = 0;
            $ffi->$toUtf8($cbuf, $byteCap, \FFI::addr($utf8len), $buf, $n, \FFI::addr($st2));
            if ($st2->cdata > 0 || $utf8len->cdata < 0) {
                return null;
            }

            return \FFI::string($cbuf, $utf8len->cdata);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function toPattern(object $fmt): ?string
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $suffix = self::$symSuffix;
        $toPattern = 'udat_toPattern'.$suffix;
        $toUtf8 = 'u_strToUTF8'.$suffix;
        try {
            $status = \FFI::new('int32_t');
            $status->cdata = 0;
            $needed = (int) $ffi->$toPattern($fmt, 0, null, 0, \FFI::addr($status));
            $status->cdata = 0;
            $cap = max(1, $needed + 1);
            $buf = \FFI::new('uint16_t['.$cap.']');
            $n = (int) $ffi->$toPattern($fmt, 0, $buf, $cap, \FFI::addr($status));
            if ($status->cdata > 0 || $n < 0) {
                return null;
            }
            $utf8len = \FFI::new('int32_t');
            $byteCap = max(64, $n * 3 + 8);
            $cbuf = \FFI::new('char['.$byteCap.']');
            $st2 = \FFI::new('int32_t');
            $st2->cdata = 0;
            $ffi->$toUtf8($cbuf, $byteCap, \FFI::addr($utf8len), $buf, $n, \FFI::addr($st2));
            if ($st2->cdata > 0 || $utf8len->cdata < 0) {
                return null;
            }

            return \FFI::string($cbuf, $utf8len->cdata);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function close(object $fmt): void
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return;
        }
        $close = 'udat_close'.self::$symSuffix;
        try {
            $ffi->$close($fmt);
        } catch (\Throwable) {
        }
    }

    /** @return array{0: object, 1: int}|null */
    private static function utf8ToUChar(string $utf8): ?array
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $fromUtf8 = 'u_strFromUTF8'.self::$symSuffix;
        try {
            $len = \FFI::new('int32_t');
            $status = \FFI::new('int32_t');
            $status->cdata = 0;
            $ffi->$fromUtf8(null, 0, \FFI::addr($len), $utf8, \strlen($utf8), \FFI::addr($status));
            $status->cdata = 0;
            $cap = max(1, $len->cdata + 1);
            $buf = \FFI::new('uint16_t['.$cap.']');
            $ffi->$fromUtf8($buf, $cap, \FFI::addr($len), $utf8, \strlen($utf8), \FFI::addr($status));
            if ($status->cdata > 0) {
                return null;
            }

            return [$buf, (int) $len->cdata];
        } catch (\Throwable) {
            return null;
        }
    }

    private static function ffi(): ?\FFI
    {
        if (self::$ffiUnavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\class_exists(\FFI::class, false) && !\extension_loaded('FFI')) {
            self::$ffiUnavailable = true;

            return null;
        }
        $candidates = [
            ['libicui18n.so.74', '_74'],
            ['libicui18n.so.72', '_72'],
            ['libicui18n.so.71', '_71'],
            ['libicui18n.so.70', '_70'],
            ['/usr/lib/x86_64-linux-gnu/libicui18n.so.74', '_74'],
            ['/usr/lib/x86_64-linux-gnu/libicui18n.so.70', '_70'],
            ['/lib/x86_64-linux-gnu/libicui18n.so.70', '_70'],
            ['libicui18n.so', '_70'],
            ['libicui18n.dylib', ''],
        ];
        foreach ($candidates as [$lib, $suffix]) {
            try {
                self::$ffi = \FFI::cdef(self::cdefForSuffix($suffix), $lib);
                self::$symSuffix = $suffix;

                return self::$ffi;
            } catch (\Throwable) {
                self::$ffi = null;
            }
        }
        self::$ffiUnavailable = true;

        return null;
    }

    private static function cdefForSuffix(string $suffix): string
    {
        return <<<C
typedef int32_t UErrorCode;
typedef uint16_t UChar;
typedef double UDate;
typedef struct UDateFormat UDateFormat;
UDateFormat *udat_open{$suffix}(int32_t timeStyle, int32_t dateStyle, const char *locale, const UChar *tzID, int32_t tzIDLength, const UChar *pattern, int32_t patternLength, UErrorCode *status);
void udat_close{$suffix}(UDateFormat *format);
int32_t udat_format{$suffix}(const UDateFormat *format, UDate dateToFormat, UChar *result, int32_t resultLength, void *position, UErrorCode *status);
int32_t udat_toPattern{$suffix}(const UDateFormat *fmt, int8_t localized, UChar *result, int32_t resultLength, UErrorCode *status);
UChar *u_strFromUTF8{$suffix}(UChar *dest, int32_t destCapacity, int32_t *pDestLength, const char *src, int32_t srcLength, UErrorCode *pErrorCode);
char *u_strToUTF8{$suffix}(char *dest, int32_t destCapacity, int32_t *pDestLength, const UChar *src, int32_t srcLength, UErrorCode *pErrorCode);
C;
    }
}
