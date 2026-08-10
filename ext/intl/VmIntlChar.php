<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\ClassConstName;
use PHPCompiler\ext\standard\VmCallable;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
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

    /** php-src IntlChar::PROPERTY_GENERAL_CATEGORY (UCHAR_GENERAL_CATEGORY). */
    public const PROPERTY_GENERAL_CATEGORY = 0x1005;

    /** php-src IntlChar::SHORT_PROPERTY_NAME / LONG_PROPERTY_NAME (UPropertyNameChoice). */
    public const SHORT_PROPERTY_NAME = 0;
    public const LONG_PROPERTY_NAME = 1;

    /** ICU U_NO_NUMERIC_VALUE sentinel for getNumericValue(). */
    public const NO_NUMERIC_VALUE = -123456789.0;

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
            'PROPERTY_GENERAL_CATEGORY' => self::PROPERTY_GENERAL_CATEGORY,
            'SHORT_PROPERTY_NAME' => self::SHORT_PROPERTY_NAME,
            'LONG_PROPERTY_NAME' => self::LONG_PROPERTY_NAME,
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
        // Exact Zend casing for defined()/hasConstant after #25910 (#30000 / #28132).
        foreach (self::classConstants() as $name => $value) {
            $key = ClassConstName::key($name);
            $const = new Variable(Variable::TYPE_INTEGER);
            $const->int($value);
            $entry->constants[$key] = $const;
            $entry->constNames[$key] = $name;
        }
        $pubStatic = CfgFunc::FLAG_PUBLIC | CfgFunc::FLAG_STATIC;
        $methods = [
            'ord' => [new IntlCharOrd(), 'ord'],
            'chr' => [new IntlCharChr(), 'chr'],
            'charname' => [new IntlCharCharName(), 'charName'],
            'hasbinaryproperty' => [new IntlCharHasBinaryProperty(), 'hasBinaryProperty'],
            'isalpha' => [new IntlCharIsAlpha(), 'isalpha'],
            'isdigit' => [new IntlCharIsDigit(), 'isdigit'],
            'isalnum' => [new IntlCharIsAlnum(), 'isalnum'],
            'isspace' => [new IntlCharIsSpace(), 'isspace'],
            'iswhitespace' => [new IntlCharIsWhitespace(), 'isWhitespace'],
            'islower' => [new IntlCharIsLower(), 'islower'],
            'isupper' => [new IntlCharIsUpper(), 'isupper'],
            'isblank' => [new IntlCharIsBlank(), 'isblank'],
            'iscntrl' => [new IntlCharIsCntrl(), 'iscntrl'],
            'isgraph' => [new IntlCharIsGraph(), 'isgraph'],
            'isprint' => [new IntlCharIsPrint(), 'isprint'],
            'ispunct' => [new IntlCharIsPunct(), 'ispunct'],
            'isxdigit' => [new IntlCharIsXdigit(), 'isxdigit'],
            'isbase' => [new IntlCharIsBase(), 'isbase'],
            'ismirrored' => [new IntlCharIsMirrored(), 'isMirrored'],
            'chartype' => [new IntlCharCharType(), 'charType'],
            'getblockcode' => [new IntlCharGetBlockCode(), 'getBlockCode'],
            'getcombiningclass' => [new IntlCharGetCombiningClass(), 'getCombiningClass'],
            'toupper' => [new IntlCharToUpper(), 'toupper'],
            'tolower' => [new IntlCharToLower(), 'tolower'],
            'totitle' => [new IntlCharToTitle(), 'totitle'],
            'foldcase' => [new IntlCharFoldCase(), 'foldCase'],
            'digit' => [new IntlCharDigit(), 'digit'],
            'fordigit' => [new IntlCharForDigit(), 'forDigit'],
            'istitle' => [new IntlCharIsTitle(), 'istitle'],
            'charfromname' => [new IntlCharCharFromName(), 'charFromName'],
            'getpropertyname' => [new IntlCharGetPropertyName(), 'getPropertyName'],
            'getpropertyenum' => [new IntlCharGetPropertyEnum(), 'getPropertyEnum'],
            'getpropertyvaluename' => [new IntlCharGetPropertyValueName(), 'getPropertyValueName'],
            'getpropertyvalueenum' => [new IntlCharGetPropertyValueEnum(), 'getPropertyValueEnum'],
            'getintpropertyvalue' => [new IntlCharGetIntPropertyValue(), 'getIntPropertyValue'],
            'getintpropertyminvalue' => [new IntlCharGetIntPropertyMinValue(), 'getIntPropertyMinValue'],
            'getintpropertymaxvalue' => [new IntlCharGetIntPropertyMaxValue(), 'getIntPropertyMaxValue'],
            'getunicodeversion' => [new IntlCharGetUnicodeVersion(), 'getUnicodeVersion'],
            'getnumericvalue' => [new IntlCharGetNumericValue(), 'getNumericValue'],
            'chardigitvalue' => [new IntlCharCharDigitValue(), 'charDigitValue'],
            'enumcharnames' => [new IntlCharEnumCharNames(), 'enumCharNames'],
            'enumchartypes' => [new IntlCharEnumCharTypes(), 'enumCharTypes'],
            'charage' => [new IntlCharCharAge(), 'charAge'],
            'isdefined' => [new IntlCharIsDefined(), 'isdefined'],
            'isualphabetic' => [new IntlCharIsUAlphabetic(), 'isUAlphabetic'],
            'isulowercase' => [new IntlCharIsULowercase(), 'isULowercase'],
            'isuuppercase' => [new IntlCharIsUUppercase(), 'isUUppercase'],
            'isuwhitespace' => [new IntlCharIsUWhiteSpace(), 'isUWhiteSpace'],
            'chardirection' => [new IntlCharCharDirection(), 'charDirection'],
            'charmirror' => [new IntlCharCharMirror(), 'charMirror'],
            'getbidipairedbracket' => [new IntlCharGetBidiPairedBracket(), 'getBidiPairedBracket'],
            'isidstart' => [new IntlCharIsIDStart(), 'isIDStart'],
            'isidpart' => [new IntlCharIsIDPart(), 'isIDPart'],
            'isidignorable' => [new IntlCharIsIDIgnorable(), 'isIDIgnorable'],
            'isisocontrol' => [new IntlCharIsISOControl(), 'isISOControl'],
            'isjavaidstart' => [new IntlCharIsJavaIDStart(), 'isJavaIDStart'],
            'isjavaidpart' => [new IntlCharIsJavaIDPart(), 'isJavaIDPart'],
            'isjavaspacechar' => [new IntlCharIsJavaSpaceChar(), 'isJavaSpaceChar'],
            'getfc_nfkc_closure' => [new IntlCharGetFCNFKCClosure(), 'getFC_NFKC_Closure'],
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
     * Unary ICU UBool helper — php-src IntlChar ctype surface (#20821).
     *
     * @param callable(int):bool $asciiFallback
     */
    private static function unaryBoolIcu(string $icuBase, string|int $codepoint, callable $asciiFallback): bool
    {
        $cp = self::resolveCodepoint($codepoint);
        if (null === $cp) {
            return false;
        }
        $ffi = self::ffi();
        if (null !== $ffi) {
            $fn = $icuBase.self::$symSuffix;

            return 0 !== (int) $ffi->$fn($cp);
        }

        return $asciiFallback($cp);
    }

    /** IntlChar::isalnum() — php-src / ICU u_isalnum (#20821). */
    public static function isalnum(string|int $codepoint): bool
    {
        return self::unaryBoolIcu('u_isalnum', $codepoint, static fn (int $cp): bool => self::isalpha($cp) || self::isdigit($cp));
    }

    /** IntlChar::isdefined() — php-src / ICU u_isdefined (#20858). */
    public static function isdefined(string|int $codepoint): ?bool
    {
        $cp = self::resolveCodepoint($codepoint);
        if (null === $cp) {
            return null;
        }

        return self::unaryBoolIcu(
            'u_isdefined',
            $cp,
            static function (int $c): bool {
                // Noncharacters U+FDD0..FDEF and *FFFE/*FFFF are undefined.
                if (($c & 0xFFFE) === 0xFFFE) {
                    return false;
                }
                if ($c >= 0xFDD0 && $c <= 0xFDEF) {
                    return false;
                }

                return $c <= 0x10FFFF;
            }
        );
    }

    /** IntlChar::isUAlphabetic() — php-src / ICU u_isUAlphabetic (#20858). */
    public static function isUAlphabetic(string|int $codepoint): ?bool
    {
        $cp = self::resolveCodepoint($codepoint);
        if (null === $cp) {
            return null;
        }

        return self::unaryBoolIcu(
            'u_isUAlphabetic',
            $cp,
            static fn (int $c): bool => self::hasBinaryProperty($c, self::PROPERTY_ALPHABETIC)
        );
    }

    /** IntlChar::isULowercase() — php-src / ICU u_isULowercase (#20858). */
    public static function isULowercase(string|int $codepoint): ?bool
    {
        $cp = self::resolveCodepoint($codepoint);
        if (null === $cp) {
            return null;
        }

        return self::unaryBoolIcu(
            'u_isULowercase',
            $cp,
            static fn (int $c): bool => self::hasBinaryProperty($c, self::PROPERTY_LOWERCASE)
        );
    }

    /** IntlChar::isUUppercase() — php-src / ICU u_isUUppercase (#20858). */
    public static function isUUppercase(string|int $codepoint): ?bool
    {
        $cp = self::resolveCodepoint($codepoint);
        if (null === $cp) {
            return null;
        }

        return self::unaryBoolIcu(
            'u_isUUppercase',
            $cp,
            static fn (int $c): bool => self::hasBinaryProperty($c, self::PROPERTY_UPPERCASE)
        );
    }

    /** IntlChar::isUWhiteSpace() — php-src / ICU u_isUWhiteSpace (#20858). */
    public static function isUWhiteSpace(string|int $codepoint): ?bool
    {
        $cp = self::resolveCodepoint($codepoint);
        if (null === $cp) {
            return null;
        }

        return self::unaryBoolIcu(
            'u_isUWhiteSpace',
            $cp,
            static fn (int $c): bool => self::hasBinaryProperty($c, self::PROPERTY_WHITE_SPACE)
        );
    }

    /** IntlChar::charDirection() — php-src / ICU u_charDirection (#20858). */
    public static function charDirection(string|int $codepoint): ?int
    {
        $cp = self::resolveCodepoint($codepoint);
        if (null === $cp) {
            return null;
        }

        return self::unaryIntIcu(
            'u_charDirection',
            $cp,
            static function (int $c): int {
                // U_LEFT_TO_RIGHT = 0 for ASCII letters/digits; U_OTHER_NEUTRAL ≈ 13 for punctuation.
                if (($c >= 0x41 && $c <= 0x5A) || ($c >= 0x61 && $c <= 0x7A) || ($c >= 0x30 && $c <= 0x39)) {
                    return 0;
                }

                return 13;
            }
        );
    }

    /**
     * IntlChar::charMirror() — php-src / ICU u_charMirror (#20858).
     *
     * @return int|string|null
     */
    public static function charMirror(string|int $codepoint)
    {
        return self::mirrorLike('u_charMirror', $codepoint);
    }

    /**
     * IntlChar::getBidiPairedBracket() — php-src / ICU u_getBidiPairedBracket (#20858).
     *
     * @return int|string|null
     */
    public static function getBidiPairedBracket(string|int $codepoint)
    {
        return self::mirrorLike('u_getBidiPairedBracket', $codepoint);
    }

    /**
     * Shared IC_CHAR_METHOD_CHAR shape — return UTF-8 string when input was string.
     *
     * @return int|string|null
     */
    private static function mirrorLike(string $icuBase, string|int $codepoint)
    {
        $asString = \is_string($codepoint);
        $cp = self::resolveCodepoint($codepoint);
        if (null === $cp) {
            return null;
        }
        $ffi = self::ffi();
        if (null !== $ffi) {
            $fn = $icuBase.self::$symSuffix;
            $out = (int) $ffi->$fn($cp);
        } else {
            $pairs = [
                0x28 => 0x29, 0x29 => 0x28,
                0x5B => 0x5D, 0x5D => 0x5B,
                0x7B => 0x7D, 0x7D => 0x7B,
                0x3C => 0x3E, 0x3E => 0x3C,
            ];
            $out = $pairs[$cp] ?? $cp;
        }

        return $asString ? (self::chr($out) ?? '') : $out;
    }

    /**
     * IntlChar::charAge() — php-src / ICU u_charAge (#20858).
     *
     * @return HashTable|null 4-element version array
     */
    public static function charAge(string|int $codepoint): ?HashTable
    {
        $cp = self::resolveCodepoint($codepoint);
        if (null === $cp) {
            return null;
        }
        $parts = [0, 0, 0, 0];
        $ffi = self::ffi();
        if (null !== $ffi) {
            $fn = 'u_charAge'.self::$symSuffix;
            $arr = $ffi->new('uint8_t[4]');
            $ffi->$fn($cp, $arr);
            for ($i = 0; $i < 4; ++$i) {
                $parts[$i] = (int) $arr[$i];
            }
        } else {
            // ASCII was in Unicode 1.1.
            $parts = ($cp >= 0 && $cp <= 0x7F) ? [1, 1, 0, 0] : [0, 0, 0, 0];
        }
        $ht = new HashTable();
        foreach ($parts as $v) {
            $slot = new Variable();
            $slot->int($v);
            $ht->append($slot);
        }

        return $ht;
    }

    /**
     * IntlChar::enumCharNames() — php-src / ICU u_enumCharNames (#20858).
     *
     * Iterates [start, limit) invoking $callback(codepoint, nameChoice, name).
     */
    public static function enumCharNames(
        Context $ctx,
        Variable $callback,
        string|int $start,
        string|int $limit,
        int $nameChoice = self::UNICODE_CHAR_NAME
    ): bool {
        $startCp = self::resolveCodepoint($start);
        $limitCp = self::resolveCodepoint($limit);
        if (null === $startCp || null === $limitCp) {
            return false;
        }
        for ($cp = $startCp; $cp < $limitCp; ++$cp) {
            $name = self::charName($cp, $nameChoice);
            if (null === $name || '' === $name) {
                continue;
            }
            $argCp = new Variable();
            $argCp->int($cp);
            $argChoice = new Variable();
            $argChoice->int($nameChoice);
            $argName = new Variable();
            $argName->string($name);
            try {
                VmCallable::invokeAs('IntlChar::enumCharNames', $ctx, $callback, $argCp, $argChoice, $argName);
            } catch (\Throwable) {
                IntlError::set(IntlError::U_INTERNAL_PROGRAM_ERROR, 'enumCharNames callback failed');

                return false;
            }
        }

        return true;
    }

    /**
     * IntlChar::enumCharTypes() — php-src / ICU u_enumCharTypes (#20937).
     *
     * Invokes $callback($start, $limit, $type) for each contiguous general-category
     * range. $start is inclusive and $limit is exclusive (php-src uchar.c).
     */
    public static function enumCharTypes(Context $ctx, Variable $callback): void
    {
        foreach (self::collectCharTypeRanges() as [$start, $limit, $type]) {
            $argStart = new Variable();
            $argStart->int($start);
            $argLimit = new Variable();
            $argLimit->int($limit);
            $argType = new Variable();
            $argType->int($type);
            try {
                VmCallable::invokeAs(
                    'IntlChar::enumCharTypes',
                    $ctx,
                    $callback,
                    $argStart,
                    $argLimit,
                    $argType
                );
            } catch (\Throwable) {
                IntlError::set(IntlError::U_INTERNAL_PROGRAM_ERROR, 'enumCharTypes callback failed');

                return;
            }
        }
    }

    /**
     * Collect ICU/php-src enumCharTypes ranges as [start, limit, type] triples.
     *
     * @return list<array{0: int, 1: int, 2: int}>
     */
    private static function collectCharTypeRanges(): array
    {
        $ffi = self::ffi();
        if (null !== $ffi) {
            $fn = 'u_enumCharTypes'.self::$symSuffix;
            /** @var list<array{0: int, 1: int, 2: int}> $ranges */
            $ranges = [];
            try {
                $ffi->$fn(static function ($context, int $start, int $limit, int $type) use (&$ranges): int {
                    $ranges[] = [$start, $limit, $type];

                    return 1;
                }, null);
                if ([] !== $ranges) {
                    return $ranges;
                }
                // Empty result usually means FFI callbacks are unavailable (e.g. some AOT
                // hosts); fall through to PHP coalesce via charType().
            } catch (\Throwable) {
                // Fall through to PHP coalesce via charType().
            }
        }

        return self::coalesceCharTypeRangesViaCharType();
    }

    /**
     * ASCII/ICU-fallback path: coalesce contiguous u_charType values over 0..0x110000.
     *
     * @return list<array{0: int, 1: int, 2: int}>
     */
    private static function coalesceCharTypeRangesViaCharType(): array
    {
        $unicodeLimit = 0x110000;
        /** @var list<array{0: int, 1: int, 2: int}> $ranges */
        $ranges = [];
        $rangeStart = 0;
        $curType = self::charType(0);
        for ($cp = 1; $cp <= $unicodeLimit; ++$cp) {
            $nextType = ($cp < $unicodeLimit) ? self::charType($cp) : -1;
            if ($nextType !== $curType) {
                $ranges[] = [$rangeStart, $cp, $curType];
                $rangeStart = $cp;
                $curType = $nextType;
            }
        }

        return $ranges;
    }

    /** IntlChar::isspace() — php-src / ICU u_isspace (#20821). */
    public static function isspace(string|int $codepoint): bool
    {
        return self::unaryBoolIcu(
            'u_isspace',
            $codepoint,
            static fn (int $cp): bool => \in_array($cp, [0x09, 0x0A, 0x0B, 0x0C, 0x0D, 0x20], true)
        );
    }

    /**
     * IntlChar::isWhitespace() — php-src / ICU u_isWhitespace (#22405).
     *
     * Java Character.isWhitespace semantics (distinct from u_isspace / u_isUWhiteSpace):
     * Zs/Zl/Zp except no-break spaces, plus HT/LF/VT/FF/CR and C0 separators 0x1C..0x1F.
     */
    public static function isWhitespace(string|int $codepoint): bool
    {
        return self::unaryBoolIcu(
            'u_isWhitespace',
            $codepoint,
            static fn (int $cp): bool => \in_array(
                $cp,
                [0x09, 0x0A, 0x0B, 0x0C, 0x0D, 0x1C, 0x1D, 0x1E, 0x1F, 0x20],
                true
            )
        );
    }

    /** IntlChar::isIDStart() — php-src / ICU u_isIDStart (#20938). */
    public static function isIDStart(string|int $codepoint): bool
    {
        return self::unaryBoolIcu(
            'u_isIDStart',
            $codepoint,
            static fn (int $cp): bool => self::isalpha($cp)
        );
    }

    /** IntlChar::isIDPart() — php-src / ICU u_isIDPart (#20938). */
    public static function isIDPart(string|int $codepoint): bool
    {
        return self::unaryBoolIcu(
            'u_isIDPart',
            $codepoint,
            static function (int $cp): bool {
                return self::isalpha($cp)
                    || self::isdigit($cp)
                    || 0x5F === $cp
                    || self::isIDIgnorable($cp);
            }
        );
    }

    /** IntlChar::isIDIgnorable() — php-src / ICU u_isIDIgnorable (#20938). */
    public static function isIDIgnorable(string|int $codepoint): bool
    {
        return self::unaryBoolIcu(
            'u_isIDIgnorable',
            $codepoint,
            static function (int $cp): bool {
                // ISO controls excluding ASCII whitespace controls (tab/LF/VT/FF/CR).
                if ($cp <= 0x9F) {
                    return self::isISOControl($cp)
                        && !\in_array($cp, [0x09, 0x0A, 0x0B, 0x0C, 0x0D], true);
                }

                return false;
            }
        );
    }

    /** IntlChar::isISOControl() — php-src / ICU u_isISOControl (#20938). */
    public static function isISOControl(string|int $codepoint): bool
    {
        return self::unaryBoolIcu(
            'u_isISOControl',
            $codepoint,
            static fn (int $cp): bool => ($cp >= 0x00 && $cp <= 0x1F) || ($cp >= 0x7F && $cp <= 0x9F)
        );
    }

    /** IntlChar::isJavaIDStart() — php-src / ICU u_isJavaIDStart (#20938). */
    public static function isJavaIDStart(string|int $codepoint): bool
    {
        return self::unaryBoolIcu(
            'u_isJavaIDStart',
            $codepoint,
            static function (int $cp): bool {
                // Letters + currency ($=Sc) + connector (_=Pc) for ASCII fallback.
                return self::isalpha($cp) || 0x24 === $cp || 0x5F === $cp;
            }
        );
    }

    /** IntlChar::isJavaIDPart() — php-src / ICU u_isJavaIDPart (#20938). */
    public static function isJavaIDPart(string|int $codepoint): bool
    {
        return self::unaryBoolIcu(
            'u_isJavaIDPart',
            $codepoint,
            static function (int $cp): bool {
                return self::isJavaIDStart($cp)
                    || self::isdigit($cp)
                    || self::isIDIgnorable($cp);
            }
        );
    }

    /** IntlChar::isJavaSpaceChar() — php-src / ICU u_isJavaSpaceChar (#20938). */
    public static function isJavaSpaceChar(string|int $codepoint): bool
    {
        return self::unaryBoolIcu(
            'u_isJavaSpaceChar',
            $codepoint,
            // Java Character.isSpaceChar — Zs/Zl/Zp only (ASCII: U+0020).
            static fn (int $cp): bool => 0x20 === $cp
        );
    }

    /**
     * IntlChar::getFC_NFKC_Closure() — php-src / ICU u_getFC_NFKC_Closure (#20938).
     *
     * Returns empty string when the property is empty; null on invalid code point.
     */
    public static function getFC_NFKC_Closure(string|int $codepoint): ?string
    {
        $cp = self::resolveCodepoint($codepoint);
        if (null === $cp) {
            return null;
        }
        $ffi = self::ffi();
        if (null !== $ffi) {
            $fn = 'u_getFC_NFKC_Closure'.self::$symSuffix;
            $status = $ffi->new('UErrorCode');
            $status->cdata = 0;
            $len = (int) $ffi->$fn($cp, null, 0, \FFI::addr($status));
            if ($len <= 0) {
                return '';
            }
            $buf = $ffi->new('UChar['.($len + 1).']');
            $status->cdata = 0;
            $len = (int) $ffi->$fn($cp, $buf, $len + 1, \FFI::addr($status));
            if ((int) $status->cdata > 0 || $len < 0) {
                return '';
            }
            if (0 === $len) {
                return '';
            }

            return self::utf16BufferToUtf8($buf, $len);
        }

        return '';
    }

    /**
     * Convert a length-prefixed ICU UChar (UTF-16) buffer to UTF-8.
     *
     * @param \FFI\CData $buf UChar[]
     */
    private static function utf16BufferToUtf8(\FFI\CData $buf, int $len): string
    {
        $out = '';
        $i = 0;
        while ($i < $len) {
            $u = (int) $buf[$i] & 0xFFFF;
            if ($u >= 0xD800 && $u <= 0xDBFF && ($i + 1) < $len) {
                $u2 = (int) $buf[$i + 1] & 0xFFFF;
                if ($u2 >= 0xDC00 && $u2 <= 0xDFFF) {
                    $cp = 0x10000 + (($u - 0xD800) << 10) + ($u2 - 0xDC00);
                    $out .= UnicodeCanonical::codepointToUtf8($cp);
                    $i += 2;
                    continue;
                }
            }
            $out .= UnicodeCanonical::codepointToUtf8($u);
            ++$i;
        }

        return $out;
    }

    /** IntlChar::islower() — php-src / ICU u_islower (#20821). */
    public static function islower(string|int $codepoint): bool
    {
        return self::unaryBoolIcu(
            'u_islower',
            $codepoint,
            static fn (int $cp): bool => $cp >= 0x61 && $cp <= 0x7A
        );
    }

    /** IntlChar::isupper() — php-src / ICU u_isupper (#20821). */
    public static function isupper(string|int $codepoint): bool
    {
        return self::unaryBoolIcu(
            'u_isupper',
            $codepoint,
            static fn (int $cp): bool => $cp >= 0x41 && $cp <= 0x5A
        );
    }

    /** IntlChar::isblank() — php-src / ICU u_isblank (#20821). */
    public static function isblank(string|int $codepoint): bool
    {
        return self::unaryBoolIcu(
            'u_isblank',
            $codepoint,
            static fn (int $cp): bool => 0x09 === $cp || 0x20 === $cp
        );
    }

    /** IntlChar::iscntrl() — php-src / ICU u_iscntrl (#20821). */
    public static function iscntrl(string|int $codepoint): bool
    {
        return self::unaryBoolIcu(
            'u_iscntrl',
            $codepoint,
            static fn (int $cp): bool => ($cp >= 0x00 && $cp <= 0x1F) || 0x7F === $cp
        );
    }

    /** IntlChar::isgraph() — php-src / ICU u_isgraph (#20821). */
    public static function isgraph(string|int $codepoint): bool
    {
        return self::unaryBoolIcu(
            'u_isgraph',
            $codepoint,
            static fn (int $cp): bool => $cp >= 0x21 && $cp <= 0x7E
        );
    }

    /** IntlChar::isprint() — php-src / ICU u_isprint (#20821). */
    public static function isprint(string|int $codepoint): bool
    {
        return self::unaryBoolIcu(
            'u_isprint',
            $codepoint,
            static fn (int $cp): bool => $cp >= 0x20 && $cp <= 0x7E
        );
    }

    /** IntlChar::ispunct() — php-src / ICU u_ispunct (#20821). */
    public static function ispunct(string|int $codepoint): bool
    {
        return self::unaryBoolIcu(
            'u_ispunct',
            $codepoint,
            static function (int $cp): bool {
                return ($cp >= 0x21 && $cp <= 0x2F)
                    || ($cp >= 0x3A && $cp <= 0x40)
                    || ($cp >= 0x5B && $cp <= 0x60)
                    || ($cp >= 0x7B && $cp <= 0x7E);
            }
        );
    }

    /** IntlChar::isxdigit() — php-src / ICU u_isxdigit (#20821). */
    public static function isxdigit(string|int $codepoint): bool
    {
        return self::unaryBoolIcu(
            'u_isxdigit',
            $codepoint,
            static function (int $cp): bool {
                return ($cp >= 0x30 && $cp <= 0x39)
                    || ($cp >= 0x41 && $cp <= 0x46)
                    || ($cp >= 0x61 && $cp <= 0x66);
            }
        );
    }

    /** IntlChar::isbase() — php-src / ICU u_isbase (#20821). */
    public static function isbase(string|int $codepoint): bool
    {
        return self::unaryBoolIcu(
            'u_isbase',
            $codepoint,
            static fn (int $cp): bool => self::isalpha($cp) || self::isdigit($cp)
        );
    }

    /** IntlChar::isMirrored() — php-src / ICU u_isMirrored (#20821). */
    public static function isMirrored(string|int $codepoint): bool
    {
        return self::unaryBoolIcu(
            'u_isMirrored',
            $codepoint,
            static function (int $cp): bool {
                return \in_array($cp, [
                    0x28, 0x29, 0x3C, 0x3E, 0x5B, 0x5D, 0x7B, 0x7D,
                ], true);
            }
        );
    }

    /**
     * Unary ICU int32 helper (#20821).
     *
     * @param callable(int):int $asciiFallback
     */
    private static function unaryIntIcu(string $icuBase, string|int $codepoint, callable $asciiFallback): int
    {
        $cp = self::resolveCodepoint($codepoint);
        if (null === $cp) {
            return 0;
        }
        $ffi = self::ffi();
        if (null !== $ffi) {
            $fn = $icuBase.self::$symSuffix;

            return (int) $ffi->$fn($cp);
        }

        return $asciiFallback($cp);
    }

    /** IntlChar::charType() — php-src / ICU u_charType (#20821). */
    public static function charType(string|int $codepoint): int
    {
        return self::unaryIntIcu(
            'u_charType',
            $codepoint,
            static function (int $cp): int {
                if (($cp >= 0x41 && $cp <= 0x5A) || ($cp >= 0x61 && $cp <= 0x7A)) {
                    return ($cp >= 0x41 && $cp <= 0x5A) ? 1 : 2;
                }
                if ($cp >= 0x30 && $cp <= 0x39) {
                    return 9;
                }
                if (\in_array($cp, [0x09, 0x0A, 0x0B, 0x0C, 0x0D, 0x20], true)) {
                    return 12;
                }

                return 0;
            }
        );
    }

    /** IntlChar::getBlockCode() — php-src / ICU ublock_getCode (#20821). */
    public static function getBlockCode(string|int $codepoint): int
    {
        return self::unaryIntIcu(
            'ublock_getCode',
            $codepoint,
            static function (int $cp): int {
                return ($cp >= 0 && $cp <= 0x7F) ? 1 : 0;
            }
        );
    }

    /** IntlChar::getCombiningClass() — php-src / ICU u_getCombiningClass (#20821). */
    public static function getCombiningClass(string|int $codepoint): int
    {
        return self::unaryIntIcu(
            'u_getCombiningClass',
            $codepoint,
            static fn (int $cp): int => 0
        );
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

    /**
     * IntlChar::charFromName() — php-src / ICU u_charFromName (#20787).
     */
    public static function charFromName(string $name, int $nameChoice = self::UNICODE_CHAR_NAME): ?int
    {
        $ffi = self::ffi();
        if (null !== $ffi) {
            $fn = 'u_charFromName'.self::$symSuffix;
            $status = $ffi->new('UErrorCode');
            $status->cdata = 0;
            $cp = (int) $ffi->$fn($nameChoice, $name, \FFI::addr($status));
            if ((int) $status->cdata > 0 || $cp < 0) {
                return null;
            }

            return self::isValidCodepoint($cp) ? $cp : null;
        }
        $upper = strtoupper(trim($name));
        if (preg_match('/^LATIN CAPITAL LETTER ([A-Z])$/', $upper, $m)) {
            return \ord($m[1]);
        }
        if (preg_match('/^LATIN SMALL LETTER ([A-Z])$/', $upper, $m)) {
            return \ord(strtolower($m[1]));
        }
        if ('COPYRIGHT SIGN' === $upper) {
            return 0xA9;
        }

        return null;
    }

    /**
     * IntlChar::getPropertyName() — php-src / ICU u_getPropertyName (#20787).
     *
     * @return string|false
     */
    public static function getPropertyName(int $property, int $nameChoice = self::LONG_PROPERTY_NAME): string|false
    {
        $ffi = self::ffi();
        if (null !== $ffi) {
            $fn = 'u_getPropertyName'.self::$symSuffix;
            $ptr = $ffi->$fn($property, $nameChoice);
            if (null === $ptr || false === $ptr) {
                return false;
            }
            if (\is_string($ptr)) {
                return '' === $ptr ? false : $ptr;
            }

            return \FFI::string($ptr);
        }
        if (self::PROPERTY_ALPHABETIC === $property) {
            return self::SHORT_PROPERTY_NAME === $nameChoice ? 'Alpha' : 'Alphabetic';
        }
        if (self::PROPERTY_UPPERCASE === $property) {
            return self::SHORT_PROPERTY_NAME === $nameChoice ? 'Upper' : 'Uppercase';
        }

        return false;
    }

    /**
     * IntlChar::getPropertyEnum() — php-src / ICU u_getPropertyEnum (#20787).
     */
    public static function getPropertyEnum(string $alias): int
    {
        $ffi = self::ffi();
        if (null !== $ffi) {
            $fn = 'u_getPropertyEnum'.self::$symSuffix;

            return (int) $ffi->$fn($alias);
        }
        $map = [
            'alphabetic' => self::PROPERTY_ALPHABETIC,
            'alpha' => self::PROPERTY_ALPHABETIC,
            'uppercase' => self::PROPERTY_UPPERCASE,
            'upper' => self::PROPERTY_UPPERCASE,
            'lowercase' => self::PROPERTY_LOWERCASE,
            'lower' => self::PROPERTY_LOWERCASE,
            'general_category' => self::PROPERTY_GENERAL_CATEGORY,
            'gc' => self::PROPERTY_GENERAL_CATEGORY,
        ];

        return $map[strtolower($alias)] ?? -1;
    }

    /**
     * IntlChar::getPropertyValueName() — php-src / ICU u_getPropertyValueName (#20787).
     *
     * @return string|false
     */
    public static function getPropertyValueName(
        int $property,
        int $value,
        int $nameChoice = self::LONG_PROPERTY_NAME
    ): string|false {
        $ffi = self::ffi();
        if (null !== $ffi) {
            $fn = 'u_getPropertyValueName'.self::$symSuffix;
            $ptr = $ffi->$fn($property, $value, $nameChoice);
            if (null === $ptr || false === $ptr) {
                return false;
            }
            if (\is_string($ptr)) {
                return '' === $ptr ? false : $ptr;
            }

            return \FFI::string($ptr);
        }
        if (self::PROPERTY_ALPHABETIC === $property && 1 === $value) {
            return self::SHORT_PROPERTY_NAME === $nameChoice ? 'Y' : 'Yes';
        }
        if (self::PROPERTY_GENERAL_CATEGORY === $property && 1 === $value) {
            return self::SHORT_PROPERTY_NAME === $nameChoice ? 'Lu' : 'Uppercase_Letter';
        }

        return false;
    }

    /**
     * IntlChar::getPropertyValueEnum() — php-src / ICU u_getPropertyValueEnum (#20787).
     */
    public static function getPropertyValueEnum(int $property, string $name): int
    {
        $ffi = self::ffi();
        if (null !== $ffi) {
            $fn = 'u_getPropertyValueEnum'.self::$symSuffix;

            return (int) $ffi->$fn($property, $name);
        }
        $lc = strtolower($name);
        if (self::PROPERTY_ALPHABETIC === $property && ('yes' === $lc || 'y' === $lc || 'true' === $lc || 't' === $lc)) {
            return 1;
        }
        if (self::PROPERTY_GENERAL_CATEGORY === $property && ('lu' === $lc || 'uppercase_letter' === $lc)) {
            return 1;
        }

        return -1;
    }

    /**
     * IntlChar::getIntPropertyValue() — php-src / ICU u_getIntPropertyValue (#20787).
     */
    public static function getIntPropertyValue(string|int $codepoint, int $property): int
    {
        $cp = self::resolveCodepoint($codepoint);
        if (null === $cp) {
            return 0;
        }
        $ffi = self::ffi();
        if (null !== $ffi) {
            $fn = 'u_getIntPropertyValue'.self::$symSuffix;

            return (int) $ffi->$fn($cp, $property);
        }

        return self::hasBinaryProperty($cp, $property) ? 1 : 0;
    }

    /**
     * IntlChar::getIntPropertyMinValue() — php-src / ICU u_getIntPropertyMinValue (#20787).
     */
    public static function getIntPropertyMinValue(int $property): int
    {
        $ffi = self::ffi();
        if (null !== $ffi) {
            $fn = 'u_getIntPropertyMinValue'.self::$symSuffix;

            return (int) $ffi->$fn($property);
        }

        return 0;
    }

    /**
     * IntlChar::getIntPropertyMaxValue() — php-src / ICU u_getIntPropertyMaxValue (#20787).
     */
    public static function getIntPropertyMaxValue(int $property): int
    {
        $ffi = self::ffi();
        if (null !== $ffi) {
            $fn = 'u_getIntPropertyMaxValue'.self::$symSuffix;

            return (int) $ffi->$fn($property);
        }
        if ($property >= self::PROPERTY_ALPHABETIC && $property <= self::PROPERTY_CHANGES_WHEN_NFKC_CASEFOLDED) {
            return 1;
        }

        return -1;
    }

    /**
     * IntlChar::getUnicodeVersion() — php-src / ICU u_getUnicodeVersion (#20787).
     *
     * @return HashTable packed [major, minor, milli, micro]
     */
    public static function getUnicodeVersion(): HashTable
    {
        $parts = [0, 0, 0, 0];
        $ffi = self::ffi();
        if (null !== $ffi) {
            $fn = 'u_getUnicodeVersion'.self::$symSuffix;
            $info = $ffi->new('uint8_t[4]');
            $ffi->$fn($info);
            $parts = [(int) $info[0], (int) $info[1], (int) $info[2], (int) $info[3]];
        } else {
            $parts = [15, 0, 0, 0];
        }
        $ht = new HashTable();
        foreach ($parts as $i => $v) {
            $slot = new Variable();
            $slot->int($v);
            $ht->append($slot);
            unset($i);
        }

        return $ht;
    }

    /**
     * IntlChar::getNumericValue() — php-src / ICU u_getNumericValue (#20787).
     */
    public static function getNumericValue(string|int $codepoint): float
    {
        $cp = self::resolveCodepoint($codepoint);
        if (null === $cp) {
            return self::NO_NUMERIC_VALUE;
        }
        $ffi = self::ffi();
        if (null !== $ffi) {
            $fn = 'u_getNumericValue'.self::$symSuffix;

            return (float) $ffi->$fn($cp);
        }
        if ($cp >= 0x30 && $cp <= 0x39) {
            return (float) ($cp - 0x30);
        }

        return self::NO_NUMERIC_VALUE;
    }

    /**
     * IntlChar::charDigitValue() — php-src / ICU u_charDigitValue (#20787).
     */
    public static function charDigitValue(string|int $codepoint): int
    {
        $cp = self::resolveCodepoint($codepoint);
        if (null === $cp) {
            return -1;
        }
        $ffi = self::ffi();
        if (null !== $ffi) {
            $fn = 'u_charDigitValue'.self::$symSuffix;

            return (int) $ffi->$fn($cp);
        }
        if ($cp >= 0x30 && $cp <= 0x39) {
            return $cp - 0x30;
        }

        return -1;
    }

    public static function coerceStringArg(Variable $var, string $function, int $position, string $name): string
    {
        return VmString::coerceStringBuiltinArg($var, $function, $position, $name);
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
typedef uint16_t UChar;
typedef int32_t UProperty;
typedef int32_t UCharNameChoice;
typedef int32_t UPropertyNameChoice;
typedef int8_t UBool;
typedef int32_t UErrorCode;
typedef uint32_t uint32_t;
typedef uint16_t uint16_t;
typedef uint8_t uint8_t;
UBool u_isalpha{$suffix}(UChar32 c);
UBool u_isdigit{$suffix}(UChar32 c);
UBool u_isalnum{$suffix}(UChar32 c);
UBool u_isspace{$suffix}(UChar32 c);
UBool u_isWhitespace{$suffix}(UChar32 c);
UBool u_islower{$suffix}(UChar32 c);
UBool u_isupper{$suffix}(UChar32 c);
UBool u_isblank{$suffix}(UChar32 c);
UBool u_iscntrl{$suffix}(UChar32 c);
UBool u_isgraph{$suffix}(UChar32 c);
UBool u_isprint{$suffix}(UChar32 c);
UBool u_ispunct{$suffix}(UChar32 c);
UBool u_isxdigit{$suffix}(UChar32 c);
UBool u_isbase{$suffix}(UChar32 c);
UBool u_isMirrored{$suffix}(UChar32 c);
UBool u_isdefined{$suffix}(UChar32 c);
UBool u_isUAlphabetic{$suffix}(UChar32 c);
UBool u_isULowercase{$suffix}(UChar32 c);
UBool u_isUUppercase{$suffix}(UChar32 c);
UBool u_isUWhiteSpace{$suffix}(UChar32 c);
UBool u_isIDStart{$suffix}(UChar32 c);
UBool u_isIDPart{$suffix}(UChar32 c);
UBool u_isIDIgnorable{$suffix}(UChar32 c);
UBool u_isISOControl{$suffix}(UChar32 c);
UBool u_isJavaIDStart{$suffix}(UChar32 c);
UBool u_isJavaIDPart{$suffix}(UChar32 c);
UBool u_isJavaSpaceChar{$suffix}(UChar32 c);
int32_t u_getFC_NFKC_Closure{$suffix}(UChar32 c, UChar *dest, int32_t destCapacity, UErrorCode *pErrorCode);
int8_t u_charType{$suffix}(UChar32 c);
int8_t u_charDirection{$suffix}(UChar32 c);
UChar32 u_charMirror{$suffix}(UChar32 c);
UChar32 u_getBidiPairedBracket{$suffix}(UChar32 c);
void u_charAge{$suffix}(UChar32 c, uint8_t versionArray[4]);
int32_t ublock_getCode{$suffix}(UChar32 c);
uint8_t u_getCombiningClass{$suffix}(UChar32 c);
UBool u_istitle{$suffix}(UChar32 c);
UChar32 u_toupper{$suffix}(UChar32 c);
UChar32 u_tolower{$suffix}(UChar32 c);
UChar32 u_totitle{$suffix}(UChar32 c);
UChar32 u_foldCase{$suffix}(UChar32 c, uint32_t options);
int8_t u_digit{$suffix}(UChar32 ch, int8_t radix);
UChar32 u_forDigit{$suffix}(int32_t digit, int8_t radix);
UBool u_hasBinaryProperty{$suffix}(UChar32 c, UProperty which);
int32_t u_charName{$suffix}(UChar32 code, UCharNameChoice nameChoice, char *buffer, int32_t bufferLength, UErrorCode *pErrorCode);
UChar32 u_charFromName{$suffix}(UCharNameChoice nameChoice, const char *name, UErrorCode *pErrorCode);
const char *u_getPropertyName{$suffix}(UProperty property, UPropertyNameChoice nameChoice);
UProperty u_getPropertyEnum{$suffix}(const char *alias);
const char *u_getPropertyValueName{$suffix}(UProperty property, int32_t value, UPropertyNameChoice nameChoice);
int32_t u_getPropertyValueEnum{$suffix}(UProperty property, const char *alias);
int32_t u_getIntPropertyValue{$suffix}(UChar32 c, UProperty which);
int32_t u_getIntPropertyMinValue{$suffix}(UProperty which);
int32_t u_getIntPropertyMaxValue{$suffix}(UProperty which);
void u_getUnicodeVersion{$suffix}(uint8_t versionArray[4]);
double u_getNumericValue{$suffix}(UChar32 c);
int32_t u_charDigitValue{$suffix}(UChar32 c);
typedef int8_t (*UCharEnumTypeRange{$suffix})(void *context, UChar32 start, UChar32 limit, int8_t type);
void u_enumCharTypes{$suffix}(UCharEnumTypeRange{$suffix} enumRange, const void *context);
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


/** IntlChar::isalnum() — php-src / ICU (#20821). */
final class IntlCharIsAlnum extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isalnum');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::isalnum() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::isalnum', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmIntlChar::isalnum($codepoint));
    }
}

/** IntlChar::isspace() — php-src / ICU (#20821). */
final class IntlCharIsSpace extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isspace');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::isspace() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::isspace', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmIntlChar::isspace($codepoint));
    }
}

/** IntlChar::isWhitespace() — php-src / ICU u_isWhitespace (#22405). */
final class IntlCharIsWhitespace extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isWhitespace');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::isWhitespace() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::isWhitespace', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmIntlChar::isWhitespace($codepoint));
    }
}

/** IntlChar::islower() — php-src / ICU (#20821). */
final class IntlCharIsLower extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('islower');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::islower() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::islower', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmIntlChar::islower($codepoint));
    }
}

/** IntlChar::isupper() — php-src / ICU (#20821). */
final class IntlCharIsUpper extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isupper');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::isupper() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::isupper', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmIntlChar::isupper($codepoint));
    }
}

/** IntlChar::isblank() — php-src / ICU (#20821). */
final class IntlCharIsBlank extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isblank');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::isblank() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::isblank', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmIntlChar::isblank($codepoint));
    }
}

/** IntlChar::iscntrl() — php-src / ICU (#20821). */
final class IntlCharIsCntrl extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('iscntrl');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::iscntrl() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::iscntrl', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmIntlChar::iscntrl($codepoint));
    }
}

/** IntlChar::isgraph() — php-src / ICU (#20821). */
final class IntlCharIsGraph extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isgraph');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::isgraph() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::isgraph', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmIntlChar::isgraph($codepoint));
    }
}

/** IntlChar::isprint() — php-src / ICU (#20821). */
final class IntlCharIsPrint extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isprint');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::isprint() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::isprint', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmIntlChar::isprint($codepoint));
    }
}

/** IntlChar::ispunct() — php-src / ICU (#20821). */
final class IntlCharIsPunct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('ispunct');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::ispunct() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::ispunct', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmIntlChar::ispunct($codepoint));
    }
}

/** IntlChar::isxdigit() — php-src / ICU (#20821). */
final class IntlCharIsXdigit extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isxdigit');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::isxdigit() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::isxdigit', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmIntlChar::isxdigit($codepoint));
    }
}

/** IntlChar::isbase() — php-src / ICU (#20821). */
final class IntlCharIsBase extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isbase');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::isbase() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::isbase', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmIntlChar::isbase($codepoint));
    }
}

/** IntlChar::isMirrored() — php-src / ICU (#20821). */
final class IntlCharIsMirrored extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isMirrored');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::isMirrored() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::isMirrored', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmIntlChar::isMirrored($codepoint));
    }
}

/** IntlChar::charType() — php-src / ICU (#20821). */
final class IntlCharCharType extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('charType');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::charType() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::charType', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmIntlChar::charType($codepoint));
    }
}

/** IntlChar::getBlockCode() — php-src / ICU (#20821). */
final class IntlCharGetBlockCode extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getBlockCode');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::getBlockCode() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::getBlockCode', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmIntlChar::getBlockCode($codepoint));
    }
}

/** IntlChar::getCombiningClass() — php-src / ICU (#20821). */
final class IntlCharGetCombiningClass extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getCombiningClass');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::getCombiningClass() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::getCombiningClass', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmIntlChar::getCombiningClass($codepoint));
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

/** IntlChar::charFromName() — php-src / ICU u_charFromName (#20787). */
final class IntlCharCharFromName extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('charFromName');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::charFromName() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        $name = VmIntlChar::coerceStringArg($frame->calledArgs[0], 'IntlChar::charFromName', 0, 'name');
        $choice = $argc >= 2
            ? VmMath::parseIntBuiltinArg($frame->calledArgs[1], 'IntlChar::charFromName', 2, 'nameChoice')
            : VmIntlChar::UNICODE_CHAR_NAME;
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmIntlChar::charFromName($name, $choice);
        if (null === $result) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->int($result);
    }
}

/** IntlChar::getPropertyName() — php-src / ICU u_getPropertyName (#20787). */
final class IntlCharGetPropertyName extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getPropertyName');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::getPropertyName() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        $property = VmMath::parseIntBuiltinArg($frame->calledArgs[0], 'IntlChar::getPropertyName', 1, 'property');
        $choice = $argc >= 2
            ? VmMath::parseIntBuiltinArg($frame->calledArgs[1], 'IntlChar::getPropertyName', 2, 'nameChoice')
            : VmIntlChar::LONG_PROPERTY_NAME;
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmIntlChar::getPropertyName($property, $choice);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }
}

/** IntlChar::getPropertyEnum() — php-src / ICU u_getPropertyEnum (#20787). */
final class IntlCharGetPropertyEnum extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getPropertyEnum');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::getPropertyEnum() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $alias = VmIntlChar::coerceStringArg($frame->calledArgs[0], 'IntlChar::getPropertyEnum', 0, 'alias');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmIntlChar::getPropertyEnum($alias));
    }
}

/** IntlChar::getPropertyValueName() — php-src / ICU u_getPropertyValueName (#20787). */
final class IntlCharGetPropertyValueName extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getPropertyValueName');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::getPropertyValueName() expects between 2 and 3 arguments, %d given',
                $argc
            ));
        }
        $property = VmMath::parseIntBuiltinArg($frame->calledArgs[0], 'IntlChar::getPropertyValueName', 1, 'property');
        $value = VmMath::parseIntBuiltinArg($frame->calledArgs[1], 'IntlChar::getPropertyValueName', 2, 'value');
        $choice = $argc >= 3
            ? VmMath::parseIntBuiltinArg($frame->calledArgs[2], 'IntlChar::getPropertyValueName', 3, 'nameChoice')
            : VmIntlChar::LONG_PROPERTY_NAME;
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmIntlChar::getPropertyValueName($property, $value, $choice);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }
}

/** IntlChar::getPropertyValueEnum() — php-src / ICU u_getPropertyValueEnum (#20787). */
final class IntlCharGetPropertyValueEnum extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getPropertyValueEnum');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::getPropertyValueEnum() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $property = VmMath::parseIntBuiltinArg($frame->calledArgs[0], 'IntlChar::getPropertyValueEnum', 1, 'property');
        $name = VmIntlChar::coerceStringArg($frame->calledArgs[1], 'IntlChar::getPropertyValueEnum', 1, 'name');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmIntlChar::getPropertyValueEnum($property, $name));
    }
}

/** IntlChar::getIntPropertyValue() — php-src / ICU u_getIntPropertyValue (#20787). */
final class IntlCharGetIntPropertyValue extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getIntPropertyValue');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::getIntPropertyValue() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::getIntPropertyValue', 0);
        $property = VmMath::parseIntBuiltinArg($frame->calledArgs[1], 'IntlChar::getIntPropertyValue', 2, 'property');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmIntlChar::getIntPropertyValue($codepoint, $property));
    }
}

/** IntlChar::getIntPropertyMinValue() — php-src / ICU u_getIntPropertyMinValue (#20787). */
final class IntlCharGetIntPropertyMinValue extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getIntPropertyMinValue');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::getIntPropertyMinValue() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $property = VmMath::parseIntBuiltinArg($frame->calledArgs[0], 'IntlChar::getIntPropertyMinValue', 1, 'property');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmIntlChar::getIntPropertyMinValue($property));
    }
}

/** IntlChar::getIntPropertyMaxValue() — php-src / ICU u_getIntPropertyMaxValue (#20787). */
final class IntlCharGetIntPropertyMaxValue extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getIntPropertyMaxValue');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::getIntPropertyMaxValue() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $property = VmMath::parseIntBuiltinArg($frame->calledArgs[0], 'IntlChar::getIntPropertyMaxValue', 1, 'property');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmIntlChar::getIntPropertyMaxValue($property));
    }
}

/** IntlChar::getUnicodeVersion() — php-src / ICU u_getUnicodeVersion (#20787). */
final class IntlCharGetUnicodeVersion extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getUnicodeVersion');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (0 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::getUnicodeVersion() expects exactly 0 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(VmIntlChar::getUnicodeVersion());
    }
}

/** IntlChar::getNumericValue() — php-src / ICU u_getNumericValue (#20787). */
final class IntlCharGetNumericValue extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getNumericValue');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::getNumericValue() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::getNumericValue', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->float(VmIntlChar::getNumericValue($codepoint));
    }
}

/** IntlChar::charDigitValue() — php-src / ICU u_charDigitValue (#20787). */
final class IntlCharCharDigitValue extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('charDigitValue');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::charDigitValue() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::charDigitValue', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmIntlChar::charDigitValue($codepoint));
    }
}

/** IntlChar::isdefined() — php-src / ICU u_isdefined (#20858). */
final class IntlCharIsDefined extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isdefined');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::isdefined() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::isdefined', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmIntlChar::isdefined($codepoint);
        if (null === $result) {
            $frame->returnVar->null();
        } else {
            $frame->returnVar->bool($result);
        }
    }
}

/** IntlChar::isUAlphabetic() — php-src / ICU u_isUAlphabetic (#20858). */
final class IntlCharIsUAlphabetic extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isUAlphabetic');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::isUAlphabetic() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::isUAlphabetic', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmIntlChar::isUAlphabetic($codepoint);
        if (null === $result) {
            $frame->returnVar->null();
        } else {
            $frame->returnVar->bool($result);
        }
    }
}

/** IntlChar::isULowercase() — php-src / ICU u_isULowercase (#20858). */
final class IntlCharIsULowercase extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isULowercase');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::isULowercase() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::isULowercase', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmIntlChar::isULowercase($codepoint);
        if (null === $result) {
            $frame->returnVar->null();
        } else {
            $frame->returnVar->bool($result);
        }
    }
}

/** IntlChar::isUUppercase() — php-src / ICU u_isUUppercase (#20858). */
final class IntlCharIsUUppercase extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isUUppercase');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::isUUppercase() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::isUUppercase', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmIntlChar::isUUppercase($codepoint);
        if (null === $result) {
            $frame->returnVar->null();
        } else {
            $frame->returnVar->bool($result);
        }
    }
}

/** IntlChar::isUWhiteSpace() — php-src / ICU u_isUWhiteSpace (#20858). */
final class IntlCharIsUWhiteSpace extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isUWhiteSpace');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::isUWhiteSpace() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::isUWhiteSpace', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmIntlChar::isUWhiteSpace($codepoint);
        if (null === $result) {
            $frame->returnVar->null();
        } else {
            $frame->returnVar->bool($result);
        }
    }
}

/** IntlChar::charDirection() — php-src / ICU u_charDirection (#20858). */
final class IntlCharCharDirection extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('charDirection');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::charDirection() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::charDirection', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmIntlChar::charDirection($codepoint);
        if (null === $result) {
            $frame->returnVar->null();
        } else {
            $frame->returnVar->int($result);
        }
    }
}

/** IntlChar::charMirror() — php-src / ICU u_charMirror (#20858). */
final class IntlCharCharMirror extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('charMirror');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::charMirror() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::charMirror', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmIntlChar::charMirror($codepoint);
        if (null === $result) {
            $frame->returnVar->null();
        } elseif (\is_string($result)) {
            $frame->returnVar->string($result);
        } else {
            $frame->returnVar->int($result);
        }
    }
}

/** IntlChar::getBidiPairedBracket() — php-src / ICU u_getBidiPairedBracket (#20858). */
final class IntlCharGetBidiPairedBracket extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getBidiPairedBracket');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::getBidiPairedBracket() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::getBidiPairedBracket', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmIntlChar::getBidiPairedBracket($codepoint);
        if (null === $result) {
            $frame->returnVar->null();
        } elseif (\is_string($result)) {
            $frame->returnVar->string($result);
        } else {
            $frame->returnVar->int($result);
        }
    }
}

/** IntlChar::charAge() — php-src / ICU u_charAge (#20858). */
final class IntlCharCharAge extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('charAge');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::charAge() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::charAge', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmIntlChar::charAge($codepoint);
        if (null === $result) {
            $frame->returnVar->null();
        } else {
            $frame->returnVar->array($result);
        }
    }
}

/** IntlChar::enumCharNames() — php-src / ICU u_enumCharNames (#20858). */
final class IntlCharEnumCharNames extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('enumCharNames');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::enumCharNames() expects between 3 and 4 arguments, %d given',
                $argc
            ));
        }
        $start = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::enumCharNames', 0);
        $limit = VmIntlChar::coerceOrdArg($frame->calledArgs[1], 'IntlChar::enumCharNames', 1);
        $callback = $frame->calledArgs[2];
        $nameChoice = VmIntlChar::UNICODE_CHAR_NAME;
        if ($argc >= 4) {
            $nameChoice = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[3],
                'IntlChar::enumCharNames',
                4,
                'type'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('IntlChar::enumCharNames() requires VM context');
        }
        $ok = VmIntlChar::enumCharNames($frame->vmContext, $callback, $start, $limit, $nameChoice);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }
}

/** IntlChar::enumCharTypes() — php-src / ICU u_enumCharTypes (#20937). */
final class IntlCharEnumCharTypes extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('enumCharTypes');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::enumCharTypes() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('IntlChar::enumCharTypes() requires VM context');
        }
        VmIntlChar::enumCharTypes($frame->vmContext, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }
}

/** IntlChar::isIDStart() — php-src / ICU u_isIDStart (#20938). */
final class IntlCharIsIDStart extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isIDStart');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::isIDStart() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::isIDStart', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmIntlChar::isIDStart($codepoint));
    }
}

/** IntlChar::isIDPart() — php-src / ICU u_isIDPart (#20938). */
final class IntlCharIsIDPart extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isIDPart');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::isIDPart() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::isIDPart', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmIntlChar::isIDPart($codepoint));
    }
}

/** IntlChar::isIDIgnorable() — php-src / ICU u_isIDIgnorable (#20938). */
final class IntlCharIsIDIgnorable extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isIDIgnorable');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::isIDIgnorable() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::isIDIgnorable', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmIntlChar::isIDIgnorable($codepoint));
    }
}

/** IntlChar::isISOControl() — php-src / ICU u_isISOControl (#20938). */
final class IntlCharIsISOControl extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isISOControl');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::isISOControl() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::isISOControl', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmIntlChar::isISOControl($codepoint));
    }
}

/** IntlChar::isJavaIDStart() — php-src / ICU u_isJavaIDStart (#20938). */
final class IntlCharIsJavaIDStart extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isJavaIDStart');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::isJavaIDStart() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::isJavaIDStart', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmIntlChar::isJavaIDStart($codepoint));
    }
}

/** IntlChar::isJavaIDPart() — php-src / ICU u_isJavaIDPart (#20938). */
final class IntlCharIsJavaIDPart extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isJavaIDPart');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::isJavaIDPart() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::isJavaIDPart', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmIntlChar::isJavaIDPart($codepoint));
    }
}

/** IntlChar::isJavaSpaceChar() — php-src / ICU u_isJavaSpaceChar (#20938). */
final class IntlCharIsJavaSpaceChar extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isJavaSpaceChar');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::isJavaSpaceChar() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::isJavaSpaceChar', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmIntlChar::isJavaSpaceChar($codepoint));
    }
}

/** IntlChar::getFC_NFKC_Closure() — php-src / ICU u_getFC_NFKC_Closure (#20938). */
final class IntlCharGetFCNFKCClosure extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getFC_NFKC_Closure');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlChar::getFC_NFKC_Closure() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $codepoint = VmIntlChar::coerceOrdArg($frame->calledArgs[0], 'IntlChar::getFC_NFKC_Closure', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmIntlChar::getFC_NFKC_Closure($codepoint);
        if (null === $result) {
            $frame->returnVar->null();
        } else {
            $frame->returnVar->string($result);
        }
    }
}
