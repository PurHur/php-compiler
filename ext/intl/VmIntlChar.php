<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/**
 * IntlChar — Unicode code-point helpers (php-src ext/intl/char/char.c; #6171, #20730).
 *
 * ord/chr are pure PHP via {@see UnicodeCanonical}. Property / case / name APIs use
 * thin ICU `u_*` FFI on libicuuc when available, with ASCII fallbacks otherwise.
 */
final class VmIntlChar
{
    public const CLASS_LC = 'intlchar';

    /** php-src IntlChar::UNICODE_CHAR_NAME / U_UNICODE_CHAR_NAME */
    public const UNICODE_CHAR_NAME = 0;
    public const UNICODE_10_CHAR_NAME = 1;
    public const EXTENDED_CHAR_NAME = 2;
    public const CHAR_NAME_ALIAS = 3;

    /** php-src IntlChar::PROPERTY_* subset (icu/uchar.h UProperty). */
    public const PROPERTY_ALPHABETIC = 0;
    public const PROPERTY_ASCII_HEX_DIGIT = 1;
    public const PROPERTY_BIDI_CONTROL = 2;
    public const PROPERTY_BIDI_MIRRORED = 3;
    public const PROPERTY_DASH = 4;
    public const PROPERTY_DEFAULT_IGNORABLE_CODE_POINT = 5;
    public const PROPERTY_DEPRECATED = 6;
    public const PROPERTY_DIACRITIC = 7;
    public const PROPERTY_EXTENDER = 8;
    public const PROPERTY_FULL_COMPOSITION_EXCLUSION = 9;
    public const PROPERTY_GRAPHEME_BASE = 10;
    public const PROPERTY_GRAPHEME_EXTEND = 11;
    public const PROPERTY_GRAPHEME_LINK = 12;
    public const PROPERTY_HEX_DIGIT = 13;
    public const PROPERTY_HYPHEN = 14;
    public const PROPERTY_ID_CONTINUE = 15;
    public const PROPERTY_ID_START = 16;
    public const PROPERTY_IDEOGRAPHIC = 17;
    public const PROPERTY_IDS_BINARY_OPERATOR = 18;
    public const PROPERTY_IDS_TRINARY_OPERATOR = 19;
    public const PROPERTY_JOIN_CONTROL = 20;
    public const PROPERTY_LOGICAL_ORDER_EXCEPTION = 21;
    public const PROPERTY_LOWERCASE = 22;
    public const PROPERTY_MATH = 23;
    public const PROPERTY_NONCHARACTER_CODE_POINT = 24;
    public const PROPERTY_QUOTATION_MARK = 25;
    public const PROPERTY_RADICAL = 26;
    public const PROPERTY_SOFT_DOTTED = 27;
    public const PROPERTY_TERMINAL_PUNCTUATION = 28;
    public const PROPERTY_UNIFIED_IDEOGRAPH = 29;
    public const PROPERTY_UPPERCASE = 30;
    public const PROPERTY_WHITE_SPACE = 31;
    public const PROPERTY_XID_CONTINUE = 32;
    public const PROPERTY_XID_START = 33;
    public const PROPERTY_CASE_SENSITIVE = 34;
    public const PROPERTY_S_TERM = 35;
    public const PROPERTY_VARIATION_SELECTOR = 36;
    public const PROPERTY_NFD_INERT = 37;
    public const PROPERTY_NFKD_INERT = 38;
    public const PROPERTY_NFC_INERT = 39;
    public const PROPERTY_NFKC_INERT = 40;
    public const PROPERTY_SEGMENT_STARTER = 41;
    public const PROPERTY_PATTERN_SYNTAX = 42;
    public const PROPERTY_PATTERN_WHITE_SPACE = 43;
    public const PROPERTY_POSIX_ALNUM = 44;
    public const PROPERTY_POSIX_BLANK = 45;
    public const PROPERTY_POSIX_GRAPH = 46;
    public const PROPERTY_POSIX_PRINT = 47;
    public const PROPERTY_POSIX_XDIGIT = 48;
    public const PROPERTY_CASED = 49;
    public const PROPERTY_CASE_IGNORABLE = 50;
    public const PROPERTY_CHANGES_WHEN_LOWERCASED = 51;
    public const PROPERTY_CHANGES_WHEN_UPPERCASED = 52;
    public const PROPERTY_CHANGES_WHEN_TITLECASED = 53;
    public const PROPERTY_CHANGES_WHEN_CASEFOLDED = 54;
    public const PROPERTY_CHANGES_WHEN_CASEMAPPED = 55;
    public const PROPERTY_CHANGES_WHEN_NFKC_CASEFOLDED = 56;

    /** php-src IntlChar::FOLD_CASE_* (icu/uchar.h U_FOLD_CASE_*). */
    public const FOLD_CASE_DEFAULT = 0;
    public const FOLD_CASE_EXCLUDE_SPECIAL_I = 1;

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    private static string $symSuffix = '';

    /** @return array<string, int> */
    public static function classConstants(): array
    {
        return [
            'UNICODE_CHAR_NAME' => self::UNICODE_CHAR_NAME,
            'UNICODE_10_CHAR_NAME' => self::UNICODE_10_CHAR_NAME,
            'EXTENDED_CHAR_NAME' => self::EXTENDED_CHAR_NAME,
            'CHAR_NAME_ALIAS' => self::CHAR_NAME_ALIAS,
            'PROPERTY_ALPHABETIC' => self::PROPERTY_ALPHABETIC,
            'PROPERTY_ASCII_HEX_DIGIT' => self::PROPERTY_ASCII_HEX_DIGIT,
            'PROPERTY_BIDI_CONTROL' => self::PROPERTY_BIDI_CONTROL,
            'PROPERTY_BIDI_MIRRORED' => self::PROPERTY_BIDI_MIRRORED,
            'PROPERTY_DASH' => self::PROPERTY_DASH,
            'PROPERTY_DEFAULT_IGNORABLE_CODE_POINT' => self::PROPERTY_DEFAULT_IGNORABLE_CODE_POINT,
            'PROPERTY_DEPRECATED' => self::PROPERTY_DEPRECATED,
            'PROPERTY_DIACRITIC' => self::PROPERTY_DIACRITIC,
            'PROPERTY_EXTENDER' => self::PROPERTY_EXTENDER,
            'PROPERTY_FULL_COMPOSITION_EXCLUSION' => self::PROPERTY_FULL_COMPOSITION_EXCLUSION,
            'PROPERTY_GRAPHEME_BASE' => self::PROPERTY_GRAPHEME_BASE,
            'PROPERTY_GRAPHEME_EXTEND' => self::PROPERTY_GRAPHEME_EXTEND,
            'PROPERTY_GRAPHEME_LINK' => self::PROPERTY_GRAPHEME_LINK,
            'PROPERTY_HEX_DIGIT' => self::PROPERTY_HEX_DIGIT,
            'PROPERTY_HYPHEN' => self::PROPERTY_HYPHEN,
            'PROPERTY_ID_CONTINUE' => self::PROPERTY_ID_CONTINUE,
            'PROPERTY_ID_START' => self::PROPERTY_ID_START,
            'PROPERTY_IDEOGRAPHIC' => self::PROPERTY_IDEOGRAPHIC,
            'PROPERTY_IDS_BINARY_OPERATOR' => self::PROPERTY_IDS_BINARY_OPERATOR,
            'PROPERTY_IDS_TRINARY_OPERATOR' => self::PROPERTY_IDS_TRINARY_OPERATOR,
            'PROPERTY_JOIN_CONTROL' => self::PROPERTY_JOIN_CONTROL,
            'PROPERTY_LOGICAL_ORDER_EXCEPTION' => self::PROPERTY_LOGICAL_ORDER_EXCEPTION,
            'PROPERTY_LOWERCASE' => self::PROPERTY_LOWERCASE,
            'PROPERTY_MATH' => self::PROPERTY_MATH,
            'PROPERTY_NONCHARACTER_CODE_POINT' => self::PROPERTY_NONCHARACTER_CODE_POINT,
            'PROPERTY_QUOTATION_MARK' => self::PROPERTY_QUOTATION_MARK,
            'PROPERTY_RADICAL' => self::PROPERTY_RADICAL,
            'PROPERTY_SOFT_DOTTED' => self::PROPERTY_SOFT_DOTTED,
            'PROPERTY_TERMINAL_PUNCTUATION' => self::PROPERTY_TERMINAL_PUNCTUATION,
            'PROPERTY_UNIFIED_IDEOGRAPH' => self::PROPERTY_UNIFIED_IDEOGRAPH,
            'PROPERTY_UPPERCASE' => self::PROPERTY_UPPERCASE,
            'PROPERTY_WHITE_SPACE' => self::PROPERTY_WHITE_SPACE,
            'PROPERTY_XID_CONTINUE' => self::PROPERTY_XID_CONTINUE,
            'PROPERTY_XID_START' => self::PROPERTY_XID_START,
            'PROPERTY_CASE_SENSITIVE' => self::PROPERTY_CASE_SENSITIVE,
            'PROPERTY_S_TERM' => self::PROPERTY_S_TERM,
            'PROPERTY_VARIATION_SELECTOR' => self::PROPERTY_VARIATION_SELECTOR,
            'PROPERTY_NFD_INERT' => self::PROPERTY_NFD_INERT,
            'PROPERTY_NFKD_INERT' => self::PROPERTY_NFKD_INERT,
            'PROPERTY_NFC_INERT' => self::PROPERTY_NFC_INERT,
            'PROPERTY_NFKC_INERT' => self::PROPERTY_NFKC_INERT,
            'PROPERTY_SEGMENT_STARTER' => self::PROPERTY_SEGMENT_STARTER,
            'PROPERTY_PATTERN_SYNTAX' => self::PROPERTY_PATTERN_SYNTAX,
            'PROPERTY_PATTERN_WHITE_SPACE' => self::PROPERTY_PATTERN_WHITE_SPACE,
            'PROPERTY_POSIX_ALNUM' => self::PROPERTY_POSIX_ALNUM,
            'PROPERTY_POSIX_BLANK' => self::PROPERTY_POSIX_BLANK,
            'PROPERTY_POSIX_GRAPH' => self::PROPERTY_POSIX_GRAPH,
            'PROPERTY_POSIX_PRINT' => self::PROPERTY_POSIX_PRINT,
            'PROPERTY_POSIX_XDIGIT' => self::PROPERTY_POSIX_XDIGIT,
            'PROPERTY_CASED' => self::PROPERTY_CASED,
            'PROPERTY_CASE_IGNORABLE' => self::PROPERTY_CASE_IGNORABLE,
            'PROPERTY_CHANGES_WHEN_LOWERCASED' => self::PROPERTY_CHANGES_WHEN_LOWERCASED,
            'PROPERTY_CHANGES_WHEN_UPPERCASED' => self::PROPERTY_CHANGES_WHEN_UPPERCASED,
            'PROPERTY_CHANGES_WHEN_TITLECASED' => self::PROPERTY_CHANGES_WHEN_TITLECASED,
            'PROPERTY_CHANGES_WHEN_CASEFOLDED' => self::PROPERTY_CHANGES_WHEN_CASEFOLDED,
            'PROPERTY_CHANGES_WHEN_CASEMAPPED' => self::PROPERTY_CHANGES_WHEN_CASEMAPPED,
            'PROPERTY_CHANGES_WHEN_NFKC_CASEFOLDED' => self::PROPERTY_CHANGES_WHEN_NFKC_CASEFOLDED,
            'FOLD_CASE_DEFAULT' => self::FOLD_CASE_DEFAULT,
            'FOLD_CASE_EXCLUDE_SPECIAL_I' => self::FOLD_CASE_EXCLUDE_SPECIAL_I,
        ];
    }

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry('IntlChar');
        $entry->isInternal = true;
        foreach (self::classConstants() as $name => $value) {
            $lc = strtolower($name);
            $const = new Variable(Variable::TYPE_INTEGER);
            $const->int($value);
            $entry->constants[$lc] = $const;
            $entry->constNames[$lc] = $name;
        }
        $pubStatic = CfgFunc::FLAG_PUBLIC | CfgFunc::FLAG_STATIC;
        $methods = [
            'ord' => [new IntlCharOrd(), 'ord'],
            'chr' => [new IntlCharChr(), 'chr'],
            'charname' => [new IntlCharCharName(), 'charName'],
            'hasbinaryproperty' => [new IntlCharHasBinaryProperty(), 'hasBinaryProperty'],
            'isalpha' => [new IntlCharIsAlpha(), 'isalpha'],
            'isdigit' => [new IntlCharIsDigit(), 'isdigit'],
            'toupper' => [new IntlCharToUpper(), 'toupper'],
            'tolower' => [new IntlCharToLower(), 'tolower'],
            'totitle' => [new IntlCharToTitle(), 'totitle'],
            'foldcase' => [new IntlCharFoldCase(), 'foldCase'],
            'digit' => [new IntlCharDigit(), 'digit'],
            'fordigit' => [new IntlCharForDigit(), 'forDigit'],
            'istitle' => [new IntlCharIsTitle(), 'istitle'],
        ];
        foreach ($methods as $lc => [$handler, $name]) {
            $entry->methods[$lc] = $handler;
            $entry->methodVisibility[$lc] = $pubStatic;
            $entry->methodNames[$lc] = $name;
        }
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    /**
     * php-src IntlChar::ord — single UTF-8 code point or int code point; else null.
     */
    public static function ord(string|int $character): ?int
    {
        if (\is_int($character)) {
            return self::isValidCodepoint($character) ? $character : null;
        }
        $cps = UnicodeCanonical::utf8Codepoints($character);
        if (1 !== \count($cps)) {
            return null;
        }
        $cp = $cps[0];

        return self::isValidCodepoint($cp) ? $cp : null;
    }

    /**
     * php-src IntlChar::chr — UTF-8 for a valid Unicode scalar; else null.
     */
    public static function chr(int $codepoint): ?string
    {
        if (!self::isValidCodepoint($codepoint)) {
            return null;
        }

        return UnicodeCanonical::codepointToUtf8($codepoint);
    }

    public static function isValidCodepoint(int $codepoint): bool
    {
        return $codepoint >= 0 && $codepoint <= 0x10FFFF;
    }

    public static function coerceOrdArg(Variable $var, string $function, int $argIndex): string|int
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($character) must be of type string|int, %s given',
                $function,
                $argIndex + 1,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_INTEGER === $var->type) {
            return $var->toInt();
        }
        if (Variable::TYPE_STRING === $var->type) {
            return $var->toString();
        }
        if (Variable::TYPE_NULL === $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($character) must be of type string|int, null given',
                $function,
                $argIndex + 1
            ));
        }

        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($character) must be of type string|int, %s given',
            $function,
            $argIndex + 1,
            EnumCaseSupport::typeNameForVariable($var)
        ));
    }

    /**
     * Resolve string|int codepoint operand to a scalar code point (or null).
     */
    public static function resolveCodepoint(string|int $character): ?int
    {
        return self::ord($character);
    }

    /**
     * IntlChar::charName() — php-src / ICU u_charName (#20730).
     */
    public static function charName(string|int $codepoint, int $nameChoice = self::UNICODE_CHAR_NAME): ?string
    {
        $cp = self::resolveCodepoint($codepoint);
        if (null === $cp) {
            return null;
        }
        $ffi = self::ffi();
        if (null !== $ffi) {
            $fn = 'u_charName'.self::$symSuffix;
            $buf = $ffi->new('char[256]');
            $status = $ffi->new('UErrorCode');
            $status->cdata = 0;
            $len = (int) $ffi->$fn($cp, $nameChoice, $buf, 256, \FFI::addr($status));
            if ($len <= 0 || (int) $status->cdata > 0) {
                return '';
            }

            return \FFI::string($buf, $len);
        }
        // ASCII / small BMP fallback when ICU FFI is absent.
        if ($cp >= 0x41 && $cp <= 0x5A) {
            return 'LATIN CAPITAL LETTER '.\chr($cp);
        }
        if ($cp >= 0x61 && $cp <= 0x7A) {
            return 'LATIN SMALL LETTER '.\strtoupper(\chr($cp));
        }
        if (0xA9 === $cp) {
            return 'COPYRIGHT SIGN';
        }

        return '';
    }

    /**
     * IntlChar::hasBinaryProperty() — php-src / ICU u_hasBinaryProperty (#20730).
     */
    public static function hasBinaryProperty(string|int $codepoint, int $property): bool
    {
        $cp = self::resolveCodepoint($codepoint);
        if (null === $cp) {
            return false;
        }
        $ffi = self::ffi();
        if (null !== $ffi) {
            $fn = 'u_hasBinaryProperty'.self::$symSuffix;

            return 0 !== (int) $ffi->$fn($cp, $property);
        }

        return match ($property) {
            self::PROPERTY_ALPHABETIC => self::isalpha($cp),
            self::PROPERTY_UPPERCASE => $cp >= 0x41 && $cp <= 0x5A,
            self::PROPERTY_LOWERCASE => $cp >= 0x61 && $cp <= 0x7A,
            self::PROPERTY_WHITE_SPACE => \in_array($cp, [0x09, 0x0A, 0x0B, 0x0C, 0x0D, 0x20], true),
            default => false,
        };
    }

    /** IntlChar::isalpha() — php-src / ICU u_isalpha (#20730). */
    public static function isalpha(string|int $codepoint): bool
    {
        $cp = self::resolveCodepoint($codepoint);
        if (null === $cp) {
            return false;
        }
        $ffi = self::ffi();
        if (null !== $ffi) {
            $fn = 'u_isalpha'.self::$symSuffix;

            return 0 !== (int) $ffi->$fn($cp);
        }

        return ($cp >= 0x41 && $cp <= 0x5A) || ($cp >= 0x61 && $cp <= 0x7A);
    }

    /** IntlChar::isdigit() — php-src / ICU u_isdigit (#20730). */
    public static function isdigit(string|int $codepoint): bool
    {
        $cp = self::resolveCodepoint($codepoint);
        if (null === $cp) {
            return false;
        }
        $ffi = self::ffi();
        if (null !== $ffi) {
            $fn = 'u_isdigit'.self::$symSuffix;

            return 0 !== (int) $ffi->$fn($cp);
        }

        return $cp >= 0x30 && $cp <= 0x39;
    }

    /**
     * IntlChar::toupper() — php-src / ICU u_toupper (#20730).
     *
     * Returns int code point when input was int; UTF-8 string when input was string.
     *
     * @return int|string
     */
    public static function toupper(string|int $codepoint)
    {
        $asString = \is_string($codepoint);
        $cp = self::resolveCodepoint($codepoint);
        if (null === $cp) {
            return $asString ? '' : 0;
        }
        $out = self::caseMap($cp, 'upper');

        return $asString ? (self::chr($out) ?? '') : $out;
    }

    /**
     * IntlChar::tolower() — php-src / ICU u_tolower (#20730).
     *
     * @return int|string
     */
    public static function tolower(string|int $codepoint)
    {
        $asString = \is_string($codepoint);
        $cp = self::resolveCodepoint($codepoint);
        if (null === $cp) {
            return $asString ? '' : 0;
        }
        $out = self::caseMap($cp, 'lower');

        return $asString ? (self::chr($out) ?? '') : $out;
    }

    /**
     * IntlChar::totitle() — php-src / ICU u_totitle (#20786).
     *
     * @return int|string
     */
    public static function totitle(string|int $codepoint)
    {
        $asString = \is_string($codepoint);
        $cp = self::resolveCodepoint($codepoint);
        if (null === $cp) {
            return $asString ? '' : 0;
        }
        $out = self::caseMap($cp, 'title');

        return $asString ? (self::chr($out) ?? '') : $out;
    }

    /**
     * IntlChar::foldCase() — php-src / ICU u_foldCase (#20786).
     *
     * @return int|string
     */
    public static function foldCase(string|int $codepoint, int $options = self::FOLD_CASE_DEFAULT)
    {
        $asString = \is_string($codepoint);
        $cp = self::resolveCodepoint($codepoint);
        if (null === $cp) {
            return $asString ? '' : 0;
        }
        $ffi = self::ffi();
        if (null !== $ffi) {
            $fn = 'u_foldCase'.self::$symSuffix;
            $out = (int) $ffi->$fn($cp, $options);
        } else {
            // ASCII fold ≈ lowercase; options ignored without ICU.
            $out = self::caseMap($cp, 'lower');
        }

        return $asString ? (self::chr($out) ?? '') : $out;
    }

    /**
     * IntlChar::digit() — php-src / ICU u_digit (#20786).
     *
     * @return int|false
     */
    public static function digit(string|int $codepoint, int $base = 10): int|false
    {
        $cp = self::resolveCodepoint($codepoint);
        if (null === $cp) {
            return false;
        }
        if ($base < 2 || $base > 36) {
            return false;
        }
        $ffi = self::ffi();
        if (null !== $ffi) {
            $fn = 'u_digit'.self::$symSuffix;
            $v = (int) $ffi->$fn($cp, $base);

            return $v < 0 ? false : $v;
        }

        return self::asciiDigitValue($cp, $base);
    }

    /**
     * IntlChar::forDigit() — php-src / ICU u_forDigit (#20786).
     * Returns the code point for $digit in $base, or 0 on failure.
     */
    public static function forDigit(int $digit, int $base = 10): int
    {
        if ($base < 2 || $base > 36 || $digit < 0 || $digit >= $base) {
            return 0;
        }
        $ffi = self::ffi();
        if (null !== $ffi) {
            $fn = 'u_forDigit'.self::$symSuffix;

            return (int) $ffi->$fn($digit, $base);
        }
        if ($digit < 10) {
            return 0x30 + $digit;
        }

        return 0x61 + ($digit - 10);
    }

    /** IntlChar::istitle() — php-src / ICU u_istitle (#20786). */
    public static function istitle(string|int $codepoint): bool
    {
        $cp = self::resolveCodepoint($codepoint);
        if (null === $cp) {
            return false;
        }
        $ffi = self::ffi();
        if (null !== $ffi) {
            $fn = 'u_istitle'.self::$symSuffix;

            return 0 !== (int) $ffi->$fn($cp);
        }
        // No ASCII titlecase letters; Lt category needs ICU.
        return false;
    }

    /** @param 'upper'|'lower'|'title' $kind */
    private static function caseMap(int $cp, string $kind): int
    {
        $ffi = self::ffi();
        if (null !== $ffi) {
            $fn = match ($kind) {
                'upper' => 'u_toupper',
                'lower' => 'u_tolower',
                'title' => 'u_totitle',
            };
            $fn .= self::$symSuffix;

            return (int) $ffi->$fn($cp);
        }
        if ('lower' === $kind) {
            if ($cp >= 0x41 && $cp <= 0x5A) {
                return $cp + 0x20;
            }

            return $cp;
        }
        // upper + title share ASCII mapping without ICU.
        if ($cp >= 0x61 && $cp <= 0x7A) {
            return $cp - 0x20;
        }

        return $cp;
    }

    /** @return int|false */
    private static function asciiDigitValue(int $cp, int $base): int|false
    {
        $v = null;
        if ($cp >= 0x30 && $cp <= 0x39) {
            $v = $cp - 0x30;
        } elseif ($cp >= 0x41 && $cp <= 0x5A) {
            $v = 10 + ($cp - 0x41);
        } elseif ($cp >= 0x61 && $cp <= 0x7A) {
            $v = 10 + ($cp - 0x61);
        }
        if (null === $v || $v >= $base) {
            return false;
        }

        return $v;
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
        /** @var list<array{0: string, 1: string}> */
        $candidates = [
            ['libicuuc.so.70', '_70'],
            ['libicuuc.so.74', '_74'],
            ['libicuuc.so.72', '_72'],
            ['libicuuc.so.71', '_71'],
            ['libicuuc.so', '_70'],
            ['libicuuc.dylib', ''],
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
typedef int32_t UChar32;
typedef int32_t UProperty;
typedef int32_t UCharNameChoice;
typedef int8_t UBool;
typedef int32_t UErrorCode;
typedef uint32_t uint32_t;
UBool u_isalpha{$suffix}(UChar32 c);
UBool u_isdigit{$suffix}(UChar32 c);
UBool u_istitle{$suffix}(UChar32 c);
UChar32 u_toupper{$suffix}(UChar32 c);
UChar32 u_tolower{$suffix}(UChar32 c);
UChar32 u_totitle{$suffix}(UChar32 c);
UChar32 u_foldCase{$suffix}(UChar32 c, uint32_t options);
int8_t u_digit{$suffix}(UChar32 ch, int8_t radix);
UChar32 u_forDigit{$suffix}(int32_t digit, int8_t radix);
UBool u_hasBinaryProperty{$suffix}(UChar32 c, UProperty which);
int32_t u_charName{$suffix}(UChar32 code, UCharNameChoice nameChoice, char *buffer, int32_t bufferLength, UErrorCode *pErrorCode);
C;
    }
}

/** IntlChar::ord() — php-src ext/intl/char/char.c (#6171). */
final class IntlCharOrd extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('ord');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::ord() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $character = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::ord', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmIntlChar::ord($character);
        if (null === $result) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->int($result);
    }
}

/** IntlChar::chr() — php-src ext/intl/char/char.c (#6171). */
final class IntlCharChr extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('chr');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::chr() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[0],
            'IntlChar::chr',
            1,
            'codepoint'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmIntlChar::chr($codepoint);
        if (null === $result) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->string($result);
    }
}

/** IntlChar::charName() — php-src / ICU u_charName (#20730). */
final class IntlCharCharName extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('charName');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::charName() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::charName', 0);
        $choice = $argc >= 2
            ? VmMath::parseIntBuiltinArg($frame->calledArgs[1], 'IntlChar::charName', 2, 'nameChoice')
            : VmIntlChar::UNICODE_CHAR_NAME;
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmIntlChar::charName($codepoint, $choice);
        if (null === $result) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->string($result);
    }
}

/** IntlChar::hasBinaryProperty() — php-src / ICU u_hasBinaryProperty (#20730). */
final class IntlCharHasBinaryProperty extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('hasBinaryProperty');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::hasBinaryProperty() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::hasBinaryProperty', 0);
        $property = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[1],
            'IntlChar::hasBinaryProperty',
            2,
            'property'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmIntlChar::hasBinaryProperty($codepoint, $property));
    }
}

/** IntlChar::isalpha() — php-src / ICU u_isalpha (#20730). */
final class IntlCharIsAlpha extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isalpha');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::isalpha() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::isalpha', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmIntlChar::isalpha($codepoint));
    }
}

/** IntlChar::isdigit() — php-src / ICU u_isdigit (#20730). */
final class IntlCharIsDigit extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isdigit');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::isdigit() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::isdigit', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmIntlChar::isdigit($codepoint));
    }
}

/** IntlChar::toupper() — php-src / ICU u_toupper (#20730). */
final class IntlCharToUpper extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('toupper');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::toupper() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::toupper', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmIntlChar::toupper($codepoint);
        if (\is_string($result)) {
            $frame->returnVar->string($result);
        } else {
            $frame->returnVar->int($result);
        }
    }
}

/** IntlChar::tolower() — php-src / ICU u_tolower (#20730). */
final class IntlCharToLower extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('tolower');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::tolower() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::tolower', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmIntlChar::tolower($codepoint);
        if (\is_string($result)) {
            $frame->returnVar->string($result);
        } else {
            $frame->returnVar->int($result);
        }
    }
}

/** IntlChar::totitle() — php-src / ICU u_totitle (#20786). */
final class IntlCharToTitle extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('totitle');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::totitle() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::totitle', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmIntlChar::totitle($codepoint);
        if (\is_string($result)) {
            $frame->returnVar->string($result);
        } else {
            $frame->returnVar->int($result);
        }
    }
}

/** IntlChar::foldCase() — php-src / ICU u_foldCase (#20786). */
final class IntlCharFoldCase extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('foldCase');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::foldCase() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::foldCase', 0);
        $options = $argc >= 2
            ? VmMath::parseIntBuiltinArg($frame->calledArgs[1], 'IntlChar::foldCase', 2, 'options')
            : VmIntlChar::FOLD_CASE_DEFAULT;
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmIntlChar::foldCase($codepoint, $options);
        if (\is_string($result)) {
            $frame->returnVar->string($result);
        } else {
            $frame->returnVar->int($result);
        }
    }
}

/** IntlChar::digit() — php-src / ICU u_digit (#20786). */
final class IntlCharDigit extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('digit');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::digit() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::digit', 0);
        $base = $argc >= 2
            ? VmMath::parseIntBuiltinArg($frame->calledArgs[1], 'IntlChar::digit', 2, 'base')
            : 10;
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmIntlChar::digit($codepoint, $base);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($result);
    }
}

/** IntlChar::forDigit() — php-src / ICU u_forDigit (#20786). */
final class IntlCharForDigit extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('forDigit');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::forDigit() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        $digit = VmMath::parseIntBuiltinArg($frame->calledArgs[0], 'IntlChar::forDigit', 1, 'digit');
        $base = $argc >= 2
            ? VmMath::parseIntBuiltinArg($frame->calledArgs[1], 'IntlChar::forDigit', 2, 'base')
            : 10;
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmIntlChar::forDigit($digit, $base));
    }
}

/** IntlChar::istitle() — php-src / ICU u_istitle (#20786). */
final class IntlCharIsTitle extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('istitle');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::istitle() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::istitle', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmIntlChar::istitle($codepoint));
    }
}
