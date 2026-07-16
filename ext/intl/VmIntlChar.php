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
 * IntlChar ord/chr — Unicode code-point helpers (php-src ext/intl/char/char.c; #6171).
 *
 * PHP-in-PHP via {@see UnicodeCanonical}; no ICU FFI required for v1 surface.
 */
final class VmIntlChar
{
    public const CLASS_LC = 'intlchar';

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

    /** @return array<string, int> */
    public static function classConstants(): array
    {
        return [
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
