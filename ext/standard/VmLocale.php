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

        self::writeStringField($ht, 'decimal_point', $lc->decimal_point);
        self::writeStringField($ht, 'thousands_sep', $lc->thousands_sep);
        self::writeStringField($ht, 'grouping', $lc->grouping);
        self::writeStringField($ht, 'int_curr_symbol', $lc->int_curr_symbol);
        self::writeStringField($ht, 'currency_symbol', $lc->currency_symbol);
        self::writeStringField($ht, 'mon_decimal_point', $lc->mon_decimal_point);
        self::writeStringField($ht, 'mon_thousands_sep', $lc->mon_thousands_sep);
        self::writeStringField($ht, 'mon_grouping', $lc->mon_grouping);
        self::writeStringField($ht, 'positive_sign', $lc->positive_sign);
        self::writeStringField($ht, 'negative_sign', $lc->negative_sign);
        self::writeCharField($ht, 'int_frac_digits', (int) $lc->int_frac_digits);
        self::writeCharField($ht, 'frac_digits', (int) $lc->frac_digits);
        self::writeCharField($ht, 'p_cs_precedes', (int) $lc->p_cs_precedes);
        self::writeCharField($ht, 'p_sep_by_space', (int) $lc->p_sep_by_space);
        self::writeCharField($ht, 'n_cs_precedes', (int) $lc->n_cs_precedes);
        self::writeCharField($ht, 'n_sep_by_space', (int) $lc->n_sep_by_space);
        self::writeCharField($ht, 'p_sign_posn', (int) $lc->p_sign_posn);
        self::writeCharField($ht, 'n_sign_posn', (int) $lc->n_sign_posn);

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
                $out[] = VmString::coerceStringBuiltinArg($v, 'setlocale', 2, 'locales');
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
            $out[] = VmString::coerceStringBuiltinArg($v, 'setlocale', $i + 2, 'locales');
        }

        return $out;
    }

    private static function writeEmptyLocaleconv(HashTable $ht): void
    {
        foreach ([
            'decimal_point', 'thousands_sep', 'grouping', 'int_curr_symbol', 'currency_symbol',
            'mon_decimal_point', 'mon_thousands_sep', 'mon_grouping', 'positive_sign', 'negative_sign',
        ] as $key) {
            self::writeStringField($ht, $key, null);
        }
        foreach ([
            'int_frac_digits', 'frac_digits', 'p_cs_precedes', 'p_sep_by_space',
            'n_cs_precedes', 'n_sep_by_space', 'p_sign_posn', 'n_sign_posn',
        ] as $key) {
            self::writeCharField($ht, $key, self::CHAR_MAX);
        }
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
