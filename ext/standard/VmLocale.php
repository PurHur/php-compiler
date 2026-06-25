<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Locale helpers via libc setlocale(3) / localeconv(3) (issue #6133, #3254).
 *
 * php-src: ext/standard/locale.c — PHP_FUNCTION(setlocale), PHP_FUNCTION(localeconv)
 */
final class VmLocale
{
    private const CHAR_MAX = 127;

    /** @var array<string, int>|null */
    private static ?array $lcConstants = null;

    /** @var array<string, int>|null */
    private static ?array $nlLanginfoConstants = null;

    private static ?\FFI $ffi = null;

    /** @return array<string, int> */
    public static function lcConstants(): array
    {
        if (null !== self::$lcConstants) {
            return self::$lcConstants;
        }

        self::$lcConstants = [
            'LC_CTYPE' => 0,
            'LC_NUMERIC' => 1,
            'LC_TIME' => 2,
            'LC_COLLATE' => 3,
            'LC_MONETARY' => 4,
            'LC_MESSAGES' => 5,
            'LC_ALL' => 6,
        ];

        $ffi = self::ffi();
        if (null === $ffi) {
            return self::$lcConstants;
        }

        foreach (self::$lcConstants as $name => $fallback) {
            try {
                self::$lcConstants[$name] = (int) $ffi->{$name};
            } catch (\Throwable) {
                self::$lcConstants[$name] = $fallback;
            }
        }

        foreach (['LC_PAPER', 'LC_NAME', 'LC_ADDRESS', 'LC_TELEPHONE', 'LC_MEASUREMENT', 'LC_IDENTIFICATION'] as $name) {
            try {
                self::$lcConstants[$name] = (int) $ffi->{$name};
            } catch (\Throwable) {
            }
        }

        return self::$lcConstants;
    }

    /**
     * nl_langinfo() item constants (php-src ext/standard/basic_functions.c + langinfo.h; #3382).
     *
     * @return array<string, int>
     */
    public static function nlLanginfoConstants(): array
    {
        if (null !== self::$nlLanginfoConstants) {
            return self::$nlLanginfoConstants;
        }

        self::$nlLanginfoConstants = [
            'ABDAY_1' => 131072,
            'ABDAY_2' => 131073,
            'ABDAY_3' => 131074,
            'ABDAY_4' => 131075,
            'ABDAY_5' => 131076,
            'ABDAY_6' => 131077,
            'ABDAY_7' => 131078,
            'ABMON_1' => 131086,
            'ABMON_2' => 131087,
            'ABMON_3' => 131088,
            'ABMON_4' => 131089,
            'ABMON_5' => 131090,
            'ABMON_6' => 131091,
            'ABMON_7' => 131092,
            'ABMON_8' => 131093,
            'ABMON_9' => 131094,
            'ABMON_10' => 131095,
            'ABMON_11' => 131096,
            'ABMON_12' => 131097,
            'AM_STR' => 131110,
            'CODESET' => 14,
            'CRNCYSTR' => 262159,
            'DAY_1' => 131079,
            'DAY_2' => 131080,
            'DAY_3' => 131081,
            'DAY_4' => 131082,
            'DAY_5' => 131083,
            'DAY_6' => 131084,
            'DAY_7' => 131085,
            'D_FMT' => 131113,
            'D_T_FMT' => 131112,
            'MON_1' => 131098,
            'MON_2' => 131099,
            'MON_3' => 131100,
            'MON_4' => 131101,
            'MON_5' => 131102,
            'MON_6' => 131103,
            'MON_7' => 131104,
            'MON_8' => 131105,
            'MON_9' => 131106,
            'MON_10' => 131107,
            'MON_11' => 131108,
            'MON_12' => 131109,
            'MON_DECIMAL_POINT' => 262146,
            'MON_GROUPING' => 262148,
            'MON_THOUSANDS_SEP' => 262147,
            'PM_STR' => 131111,
            'RADIXCHAR' => 65536,
            'THOUSEP' => 65537,
            'T_FMT' => 131114,
            'T_FMT_AMPM' => 131115,
        ];

        $ffi = self::ffi();
        if (null === $ffi) {
            return self::$nlLanginfoConstants;
        }

        foreach (self::$nlLanginfoConstants as $name => $fallback) {
            try {
                self::$nlLanginfoConstants[$name] = (int) $ffi->{$name};
            } catch (\Throwable) {
                self::$nlLanginfoConstants[$name] = $fallback;
            }
        }

        return self::$nlLanginfoConstants;
    }

    /**
     * nl_langinfo() — locale item lookup (php-src ext/standard/nl_langinfo.c; #3382).
     */
    public static function nlLanginfo(int $item): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $result = $ffi->nl_langinfo($item);
        if (null === $result) {
            return false;
        }
        $text = \FFI::string($result);

        return '' === $text ? false : $text;
    }

    /**
     * @param list<Variable> $localeArgs VM arguments from index 1 onward
     */
    public static function setlocale(int $category, array $localeArgs): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $locales = self::expandLocaleArgs($localeArgs);
        if ([] === $locales) {
            $result = $ffi->setlocale($category, null);

            return self::ffiStringOrFalse($result);
        }

        foreach ($locales as $locale) {
            if (null === $locale) {
                $result = $ffi->setlocale($category, null);

                return self::ffiStringOrFalse($result);
            }
            $result = $ffi->setlocale($category, $locale);
            if (null !== $result && '' !== \FFI::string($result)) {
                return \FFI::string($result);
            }
        }

        return false;
    }

    public static function localeconv(): HashTable
    {
        $ht = new HashTable();
        $ffi = self::ffi();
        if (null === $ffi) {
            self::writeEmptyLocaleconv($ht);

            return $ht;
        }

        $lc = $ffi->localeconv();
        if (null === $lc) {
            self::writeEmptyLocaleconv($ht);

            return $ht;
        }

        $monetaryUnset = self::isMonetaryLocaleUnset($lc);

        self::writeStringField($ht, 'decimal_point', $lc->decimal_point);
        self::writeStringField($ht, 'thousands_sep', $lc->thousands_sep);
        self::writeGroupingField($ht, 'grouping', $lc->grouping);
        self::writeStringField($ht, 'int_curr_symbol', $lc->int_curr_symbol);
        self::writeStringField($ht, 'currency_symbol', $lc->currency_symbol);
        self::writeStringField($ht, 'mon_decimal_point', $lc->mon_decimal_point);
        self::writeStringField($ht, 'mon_thousands_sep', $lc->mon_thousands_sep);
        self::writeGroupingField($ht, 'mon_grouping', $lc->mon_grouping);
        self::writeStringField($ht, 'positive_sign', $lc->positive_sign);
        self::writeStringField($ht, 'negative_sign', $lc->negative_sign);
        self::writeCharField($ht, 'int_frac_digits', self::lconvCharToPhpLong((int) $lc->int_frac_digits, $monetaryUnset));
        self::writeCharField($ht, 'frac_digits', self::lconvCharToPhpLong((int) $lc->frac_digits, $monetaryUnset));
        self::writeCharField($ht, 'p_cs_precedes', self::lconvCharToPhpLong((int) $lc->p_cs_precedes, $monetaryUnset));
        self::writeCharField($ht, 'p_sep_by_space', self::lconvCharToPhpLong((int) $lc->p_sep_by_space, $monetaryUnset));
        self::writeCharField($ht, 'n_cs_precedes', self::lconvCharToPhpLong((int) $lc->n_cs_precedes, $monetaryUnset));
        self::writeCharField($ht, 'n_sep_by_space', self::lconvCharToPhpLong((int) $lc->n_sep_by_space, $monetaryUnset));
        self::writeCharField($ht, 'p_sign_posn', self::lconvCharToPhpLong((int) $lc->p_sign_posn, $monetaryUnset));
        self::writeCharField($ht, 'n_sign_posn', self::lconvCharToPhpLong((int) $lc->n_sign_posn, $monetaryUnset));

        return $ht;
    }

    /**
     * @param list<Variable> $localeArgs
     *
     * @return list<string|null>
     */
    private static function expandLocaleArgs(array $localeArgs): array
    {
        if ([] === $localeArgs) {
            return [];
        }

        $first = $localeArgs[0]->resolveIndirect();
        if (Variable::TYPE_NULL === $first->type) {
            return [null];
        }
        if (Variable::TYPE_ARRAY === $first->type) {
            $out = [];
            foreach ($first->toArray()->iterateKeyed(true) as [, $entryVar]) {
                $v = $entryVar->resolveIndirect();
                if (Variable::TYPE_NULL === $v->type) {
                    $out[] = null;
                    continue;
                }
                $out[] = self::normalizeLocaleArg(
                    VmString::coerceStringBuiltinArg($v, 'setlocale', 2, 'locales')
                );
            }

            return $out;
        }

        $out = [];
        foreach ($localeArgs as $i => $arg) {
            $v = $arg->resolveIndirect();
            if (Variable::TYPE_NULL === $v->type) {
                $out[] = null;
                continue;
            }
            $out[] = self::normalizeLocaleArg(
                VmString::coerceStringBuiltinArg($v, 'setlocale', $i + 2, 'locales')
            );
        }

        return $out;
    }

    /**
     * php-src ext/standard/locale.c — single-char "0" is the query-current-locale idiom.
     */
    private static function normalizeLocaleArg(string $locale): ?string
    {
        return '0' === $locale ? null : $locale;
    }

    private static function writeEmptyLocaleconv(HashTable $ht): void
    {
        foreach ([
            'decimal_point', 'thousands_sep', 'int_curr_symbol', 'currency_symbol',
            'mon_decimal_point', 'mon_thousands_sep', 'positive_sign', 'negative_sign',
        ] as $key) {
            self::writeStringField($ht, $key, null);
        }
        self::writeGroupingField($ht, 'grouping', null);
        self::writeGroupingField($ht, 'mon_grouping', null);
        foreach ([
            'int_frac_digits', 'frac_digits', 'p_cs_precedes', 'p_sep_by_space',
            'n_cs_precedes', 'n_sep_by_space', 'p_sign_posn', 'n_sign_posn',
        ] as $key) {
            self::writeCharField($ht, $key, self::CHAR_MAX);
        }
    }

    /** php-src locale.c — monetary char fields are CHAR_MAX when LC_MONETARY is unset. */
    private static function isMonetaryLocaleUnset(\FFI\CData $lc): bool
    {
        return '' === self::ffiStringAt($lc->currency_symbol)
            && '' === self::ffiStringAt($lc->int_curr_symbol);
    }

    /**
     * Map libc lconv char member to Zend long (ext/standard/locale.c, #10265).
     */
    private static function lconvCharToPhpLong(int $signedByte, bool $monetaryUnset): int
    {
        if ($monetaryUnset) {
            return self::CHAR_MAX;
        }
        if (self::CHAR_MAX === $signedByte || -1 === $signedByte) {
            return self::CHAR_MAX;
        }

        return $signedByte;
    }

    private static function ffiStringAt(mixed $ptr): string
    {
        if (null === $ptr) {
            return '';
        }

        return \FFI::string($ptr);
    }

    /** grouping/mon_grouping are int[] from null-terminated byte sequence (php-src locale.c). */
    private static function writeGroupingField(HashTable $ht, string $key, ?\FFI\CData $ptr): void
    {
        $var = new Variable();
        $var->array(self::groupingBytesFromPtr($ptr));
        $ht->add($key, $var);
    }

    private static function groupingBytesFromPtr(?\FFI\CData $ptr): HashTable
    {
        $arr = new HashTable();
        if (null === $ptr) {
            return $arr;
        }
        for ($i = 0; ; ++$i) {
            $byte = (int) $ptr[$i];
            if (0 === $byte) {
                break;
            }
            $v = new Variable();
            $v->int($byte);
            $arr->append($v);
        }

        return $arr;
    }

    private static function writeStringField(HashTable $ht, string $key, mixed $ptr): void
    {
        $var = new Variable();
        if (null === $ptr) {
            $var->string('');

            $ht->add($key, $var);

            return;
        }
        $text = \FFI::string($ptr);
        $var->string('' === $text ? '' : $text);
        $ht->add($key, $var);
    }

    private static function writeCharField(HashTable $ht, string $key, int $byte): void
    {
        $var = new Variable();
        if ($byte < 0) {
            $byte += 256;
        }
        if (self::CHAR_MAX === $byte) {
            $var->int(self::CHAR_MAX);
        } else {
            $var->int($byte);
        }
        $ht->add($key, $var);
    }

    private static function ffiStringOrFalse(?\FFI\CData $result): string|false
    {
        if (null === $result) {
            return false;
        }
        $text = \FFI::string($result);

        return '' === $text ? false : $text;
    }

    private static function ffi(): ?\FFI
    {
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (self::ffiDisabled()) {
            return null;
        }
        if (!\extension_loaded('ffi')) {
            return null;
        }

        $cdef = <<<'CDEF'
typedef unsigned long size_t;
enum {
    LC_CTYPE = 0,
    LC_NUMERIC = 1,
    LC_TIME = 2,
    LC_COLLATE = 3,
    LC_MONETARY = 4,
    LC_MESSAGES = 5,
    LC_ALL = 6
};
struct lconv {
    char *decimal_point;
    char *thousands_sep;
    char *grouping;
    char *int_curr_symbol;
    char *currency_symbol;
    char *mon_decimal_point;
    char *mon_thousands_sep;
    char *mon_grouping;
    char *positive_sign;
    char *negative_sign;
    char int_frac_digits;
    char frac_digits;
    char p_cs_precedes;
    char p_sep_by_space;
    char n_cs_precedes;
    char n_sep_by_space;
    char p_sign_posn;
    char n_sign_posn;
};
char *setlocale(int category, const char *locale);
struct lconv *localeconv(void);
char *nl_langinfo(int item);
CDEF;

        foreach (['libc.so.6', 'libc.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private static function ffiDisabled(): bool
    {
        $v = getenv('PHP_COMPILER_DISABLE_FFI');

        return false !== $v && '' !== $v && '0' !== $v;
    }
}
