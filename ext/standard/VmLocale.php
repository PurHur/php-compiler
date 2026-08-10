<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Locale helpers via host setlocale/localeconv/nl_langinfo (#6133, #3254, #13584).
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

    /** @return array<string, int> */
    public static function lcConstants(): array
    {
        if (null !== self::$lcConstants) {
            return self::$lcConstants;
        }

        self::$lcConstants = VmLocalePure::lcConstants();

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

        self::$nlLanginfoConstants = VmLocalePure::nlLanginfoConstants();

        return self::$nlLanginfoConstants;
    }

    /**
     * nl_langinfo() — locale item lookup (php-src ext/standard/nl_langinfo.c; #3382, #29459).
     */
    public static function nlLanginfo(int $item, ?\PHPCompiler\Frame $frame = null): string|false
    {
        // php-src ext/standard/nl_langinfo.c — invalid item: E_WARNING then false (#29459).
        if (!self::isValidNlLanginfoItem($item)) {
            self::emitInvalidNlLanginfoItemWarning($item, $frame);

            return false;
        }

        return VmLocalePure::nlLanginfo($item);
    }

    private static function emitInvalidNlLanginfoItemWarning(int $item, ?\PHPCompiler\Frame $frame): void
    {
        $message = \sprintf("nl_langinfo(): Item '%d' is not valid", $item);
        if (null !== $frame?->vmContext) {
            $frame->vmContext->errors->triggerError(
                $message,
                \PHPCompiler\VM\ErrorReporter::E_WARNING,
                '' !== $frame->scriptPath ? $frame->scriptPath : null,
                $frame->vmContext,
                $frame
            );

            return;
        }
        @\trigger_error($message, \E_WARNING);
    }

    public static function isValidNlLanginfoItem(int $item): bool
    {
        return \in_array($item, self::nlLanginfoConstants(), true);
    }

    /**
     * @param list<Variable> $localeArgs VM arguments from index 1 onward
     */
    public static function setlocale(int $category, array $localeArgs): string|false
    {
        if (!VmLocalePure::available()) {
            return false;
        }

        return VmLocalePure::setlocale($category, self::expandLocaleArgs($localeArgs));
    }

    public static function localeconv(): HashTable
    {
        $ht = new HashTable();
        $lc = VmLocalePure::localeconvArray();
        if (false === $lc) {
            self::writeEmptyLocaleconv($ht);

            return $ht;
        }

        $monetaryUnset = self::isMonetaryLocaleUnset($lc);

        // php-src ext/standard/locale.c — grouping/mon_grouping after n_sign_posn (#28154).
        self::writeStringField($ht, 'decimal_point', self::stringField($lc, 'decimal_point'));
        self::writeStringField($ht, 'thousands_sep', self::stringField($lc, 'thousands_sep'));
        self::writeStringField($ht, 'int_curr_symbol', self::stringField($lc, 'int_curr_symbol'));
        self::writeStringField($ht, 'currency_symbol', self::stringField($lc, 'currency_symbol'));
        self::writeStringField($ht, 'mon_decimal_point', self::stringField($lc, 'mon_decimal_point'));
        self::writeStringField($ht, 'mon_thousands_sep', self::stringField($lc, 'mon_thousands_sep'));
        self::writeStringField($ht, 'positive_sign', self::stringField($lc, 'positive_sign'));
        self::writeStringField($ht, 'negative_sign', self::stringField($lc, 'negative_sign'));
        self::writeCharField($ht, 'int_frac_digits', self::charField($lc, 'int_frac_digits', $monetaryUnset));
        self::writeCharField($ht, 'frac_digits', self::charField($lc, 'frac_digits', $monetaryUnset));
        self::writeCharField($ht, 'p_cs_precedes', self::charField($lc, 'p_cs_precedes', $monetaryUnset));
        self::writeCharField($ht, 'p_sep_by_space', self::charField($lc, 'p_sep_by_space', $monetaryUnset));
        self::writeCharField($ht, 'n_cs_precedes', self::charField($lc, 'n_cs_precedes', $monetaryUnset));
        self::writeCharField($ht, 'n_sep_by_space', self::charField($lc, 'n_sep_by_space', $monetaryUnset));
        self::writeCharField($ht, 'p_sign_posn', self::charField($lc, 'p_sign_posn', $monetaryUnset));
        self::writeCharField($ht, 'n_sign_posn', self::charField($lc, 'n_sign_posn', $monetaryUnset));
        self::writeGroupingField($ht, 'grouping', self::groupingField($lc, 'grouping'));
        self::writeGroupingField($ht, 'mon_grouping', self::groupingField($lc, 'mon_grouping'));

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
     * php-src ext/standard/locale.c — keep literal "0" distinct from null (query idiom vs normalized LC_ALL query).
     */
    private static function normalizeLocaleArg(string $locale): string
    {
        return $locale;
    }

    private static function writeEmptyLocaleconv(HashTable $ht): void
    {
        foreach ([
            'decimal_point', 'thousands_sep', 'int_curr_symbol', 'currency_symbol',
            'mon_decimal_point', 'mon_thousands_sep', 'positive_sign', 'negative_sign',
        ] as $key) {
            self::writeStringField($ht, $key, '');
        }
        foreach ([
            'int_frac_digits', 'frac_digits', 'p_cs_precedes', 'p_sep_by_space',
            'n_cs_precedes', 'n_sep_by_space', 'p_sign_posn', 'n_sign_posn',
        ] as $key) {
            self::writeCharField($ht, $key, self::CHAR_MAX);
        }
        // Match php-src insertion order: grouping fields after n_sign_posn (#28154).
        self::writeGroupingField($ht, 'grouping', []);
        self::writeGroupingField($ht, 'mon_grouping', []);
    }

    /** @param array<string, mixed> $lc */
    private static function isMonetaryLocaleUnset(array $lc): bool
    {
        return '' === self::stringField($lc, 'currency_symbol')
            && '' === self::stringField($lc, 'int_curr_symbol');
    }

    /**
     * @param array<string, mixed> $lc
     */
    private static function charField(array $lc, string $key, bool $monetaryUnset): int
    {
        if ($monetaryUnset) {
            return self::CHAR_MAX;
        }
        if (!\array_key_exists($key, $lc)) {
            return self::CHAR_MAX;
        }
        $value = $lc[$key];
        if (!\is_int($value) && !\is_float($value) && !\is_string($value)) {
            return self::CHAR_MAX;
        }

        return self::lconvCharToPhpLong((int) $value, false);
    }

    /**
     * @param array<string, mixed> $lc
     *
     * @return list<int>
     */
    private static function groupingField(array $lc, string $key): array
    {
        if (!\array_key_exists($key, $lc) || !\is_array($lc[$key])) {
            return [];
        }

        $out = [];
        foreach ($lc[$key] as $byte) {
            if (!\is_int($byte) && !\is_float($byte) && !\is_string($byte)) {
                continue;
            }
            $out[] = (int) $byte;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $lc
     */
    private static function stringField(array $lc, string $key): string
    {
        if (!\array_key_exists($key, $lc) || !\is_string($lc[$key])) {
            return '';
        }

        return $lc[$key];
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

    /** @param list<int> $bytes */
    private static function writeGroupingField(HashTable $ht, string $key, array $bytes): void
    {
        $var = new Variable();
        $arr = new HashTable();
        foreach ($bytes as $byte) {
            $v = new Variable();
            $v->int($byte);
            $arr->append($v);
        }
        $var->array($arr);
        $ht->add($key, $var);
    }

    private static function writeStringField(HashTable $ht, string $key, string $text): void
    {
        $var = new Variable();
        $var->string($text);
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
}
