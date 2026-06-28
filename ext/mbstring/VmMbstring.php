<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\iconv\CharsetEngine;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable;

/**
 * Shared mbstring VM helpers (php-src ext/mbstring/mbstring.c; #7014, #3239).
 */
final class VmMbstring
{
    public const MB_LTRIM = 1;
    public const MB_RTRIM = 2;
    public const MB_BOTH_TRIM = 3;

    /** @var list<int> php-src ext/mbstring/mbstring.c mb_trim_default_chars */
    private const DEFAULT_TRIM_CODEPOINTS = [
        0x20, 0x0C, 0x0A, 0x0D, 0x09, 0x0B, 0x00, 0xA0, 0x1680,
        0x2000, 0x2001, 0x2002, 0x2003, 0x2004, 0x2005, 0x2006, 0x2007,
        0x2008, 0x2009, 0x200A, 0x2028, 0x2029, 0x202F, 0x205F, 0x3000,
        0x85, 0x180E,
    ];

    public static function coerceModeArg(Variable $var, string $function, int $argIndex = 1): int
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($mode) must be of type int, %s given',
                $function,
                $argIndex + 1,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($mode) must be of type int, %s given',
                $function,
                $argIndex + 1,
                self::typeLabel($var)
            ));
        }

        return self::validateMode($var->toInt(), $function, $argIndex);
    }

    public static function coerceEncodingArg(
        Variable $var,
        string $function,
        int $argIndex = 2,
        string $default = 'UTF-8'
    ): string {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return $default;
        }

        return self::coerceEncodingString($var, $function, $argIndex);
    }

    public static function coerceEncodingString(Variable $var, string $function, int $argIndex = 2): string
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($encoding) must be of type ?string, %s given',
                $function,
                $argIndex + 1,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_STRING !== $var->type && Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($encoding) must be of type ?string, %s given',
                $function,
                $argIndex + 1,
                self::typeLabel($var)
            ));
        }

        return $var->toString();
    }

    /** php-src mbfl_name2encoding — mbstring metadata builtins (#13100). */
    public static function coerceMbEncodingNameArg(Variable $var, string $function, int $argIndex = 0): string
    {
        $name = self::coerceEncodingString($var, $function, $argIndex);

        return MbstringEncodingRegistry::assertValid($name, $function, $argIndex);
    }

    public static function validateMode(int $mode, string $function, int $argIndex = 1): int
    {
        if ($mode < MbstringConstants::MB_CASE_UPPER || $mode > MbstringConstants::MB_CASE_TITLE) {
            throw new \ValueError(sprintf(
                '%s(): Argument #%d ($mode) must be one of the MB_CASE_* constants',
                $function,
                $argIndex + 1
            ));
        }

        return $mode;
    }

    /** php-src mbstring.c pseudo-encoding for htmlentities / html_entity_decode round-trip. */
    public static function isHtmlEntitiesEncoding(string $encoding): bool
    {
        return 0 === strcasecmp($encoding, 'HTML-ENTITIES');
    }

    /**
     * mb_convert_encoding() core — charset + HTML-ENTITIES pseudo-encoding (#11212).
     */
    public static function convertEncoding(string $source, string $to, string $from): string|false
    {
        $toHtml = self::isHtmlEntitiesEncoding($to);
        $fromHtml = self::isHtmlEntitiesEncoding($from);
        if ($fromHtml) {
            $utf8 = VmString::html_entity_decode($source, ENT_COMPAT | ENT_HTML401, 'UTF-8');
            if ($toHtml) {
                return $utf8;
            }

            return CharsetEngine::convert('UTF-8', $to, $utf8);
        }
        if ($toHtml) {
            $utf8 = CharsetEngine::convert($from, 'UTF-8', $source);
            if (false === $utf8) {
                return false;
            }

            return VmString::htmlentities($utf8, ENT_COMPAT, 'UTF-8', true);
        }

        return CharsetEngine::convert($from, $to, $source);
    }

    public static function convertCase(string $source, int $mode, string $encoding = 'UTF-8'): string
    {
        if ('UTF-8' !== $encoding && 'ASCII' !== $encoding && '8BIT' !== $encoding) {
            throw new \LogicException(
                'mb_convert_case() requires mbstring for encoding '.$encoding.' in this compiler build'
            );
        }

        return match ($mode) {
            MbstringConstants::MB_CASE_UPPER => 'UTF-8' === $encoding
                ? self::utf8Upper($source)
                : self::asciiUpper($source),
            MbstringConstants::MB_CASE_LOWER => 'UTF-8' === $encoding
                ? self::utf8Lower($source)
                : self::asciiLower($source),
            MbstringConstants::MB_CASE_TITLE => 'UTF-8' === $encoding
                ? self::utf8Title($source)
                : self::asciiTitle($source),
            default => throw new \ValueError('mb_convert_case(): Argument #2 ($mode) must be one of the MB_CASE_* constants'),
        };
    }

    private static function utf8Upper(string $source): string
    {
        $out = '';
        foreach (self::codepointsInString($source, 'UTF-8') as $cp) {
            foreach (Utf8CaseMap::toUpperCodepoints($cp) as $upperCp) {
                $out .= self::encodeUtf8Codepoint($upperCp);
            }
        }

        return $out;
    }

    private static function utf8Lower(string $source): string
    {
        $out = '';
        foreach (self::codepointsInString($source, 'UTF-8') as $cp) {
            $out .= self::encodeUtf8Codepoint(Utf8CaseMap::toLower($cp));
        }

        return $out;
    }

    private static function utf8Title(string $source): string
    {
        $out = '';
        $upperNext = true;
        foreach (self::codepointsInString($source, 'UTF-8') as $cp) {
            if ($upperNext) {
                $upperCps = Utf8CaseMap::toUpperCodepoints($cp);
                $out .= self::encodeUtf8Codepoint($upperCps[0]);
                for ($ui = 1, $un = \count($upperCps); $ui < $un; ++$ui) {
                    $out .= self::encodeUtf8Codepoint($upperCps[$ui]);
                }
                $upperNext = false;
            } else {
                $cp = Utf8CaseMap::toLower($cp);
                $out .= self::encodeUtf8Codepoint($cp);
            }
            if (Utf8CaseMap::isTitleDelimiter($cp)) {
                $upperNext = true;
            }
        }

        return $out;
    }

    private static function asciiUpper(string $source): string
    {
        return strtr($source, 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ');
    }

    private static function asciiLower(string $source): string
    {
        return strtr($source, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    }

    private static function asciiTitle(string $source): string
    {
        return ucwords(self::asciiLower($source));
    }

    public static function coerceOffsetArg(Variable $var, string $function, int $argIndex = 2): int
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($offset) must be of type int, %s given',
                $function,
                $argIndex + 1,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($offset) must be of type int, %s given',
                $function,
                $argIndex + 1,
                self::typeLabel($var)
            ));
        }

        return $var->toInt();
    }

    public static function coercePartArg(Variable $var, string $function, int $argIndex = 2): bool
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($before_needle) must be of type bool, %s given',
                $function,
                $argIndex + 1,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_BOOLEAN !== $var->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($before_needle) must be of type bool, %s given',
                $function,
                $argIndex + 1,
                self::typeLabel($var)
            ));
        }

        return $var->toBool();
    }

    /**
     * @return int|false
     */
    public static function stripos(string $haystack, string $needle, int $offset = 0, string $encoding = 'UTF-8')
    {
        return self::utf8Strpos($haystack, $needle, $offset, true, $encoding, 'mb_stripos');
    }

    /**
     * @return int|false
     */
    public static function strrpos(string $haystack, string $needle, int $offset = 0, string $encoding = 'UTF-8')
    {
        return self::utf8Strrpos($haystack, $needle, $offset, false, $encoding, 'mb_strrpos');
    }

    /**
     * @return int|false
     */
    public static function strpos(string $haystack, string $needle, int $offset = 0, string $encoding = 'UTF-8')
    {
        return self::utf8Strpos($haystack, $needle, $offset, false, $encoding, 'mb_strpos');
    }

    public static function substr(
        string $string,
        int $start,
        ?int $length = null,
        string $encoding = 'UTF-8'
    ): string {
        self::assertSubstrCountEncoding($encoding, 'mb_substr');
        $charLen = VmString::utf8CharLength($string);
        if ($start < 0) {
            $start += $charLen;
        }
        if ($start < 0) {
            $start = 0;
        }
        if ($start > $charLen) {
            return '';
        }
        if (null === $length) {
            $length = $charLen - $start;
        } elseif ($length < 0) {
            $length = $charLen - $start + $length;
            if ($length < 0) {
                return '';
            }
        }
        if ($length <= 0) {
            return '';
        }

        return VmString::utf8CharSubstr($string, $start, $length);
    }

    /**
     * mb_strwidth() — terminal display width (php-src ext/mbstring/mbstring.c mb_get_strwidth; #3495).
     */
    public static function strwidth(string $string, string $encoding = 'UTF-8'): int
    {
        self::assertSubstrCountEncoding($encoding, 'mb_strwidth');
        if ('ASCII' === $encoding || '8BIT' === $encoding) {
            return VmString::byteLength($string);
        }

        $width = 0;
        $charLen = VmString::utf8CharLength($string);
        for ($i = 0; $i < $charLen; ++$i) {
            $width += EastAsianWidthTable::characterWidth(
                self::decodeUtf8Char(VmString::utf8CharSubstr($string, $i, 1))
            );
        }

        return $width;
    }

    /**
     * mb_strimwidth() — truncate to display width with optional trim marker (php-src mb_trim_string; #3495).
     */
    public static function strimwidth(
        string $string,
        int $from,
        int $width,
        string $trimmarker = '',
        string $encoding = 'UTF-8'
    ): string {
        self::assertSubstrCountEncoding($encoding, 'mb_strimwidth');
        if (0 !== $from) {
            $charLen = 'UTF-8' === $encoding
                ? VmString::utf8CharLength($string)
                : VmString::byteLength($string);
            if ($from < 0) {
                $from += $charLen;
            }
            if ($from < 0 || $from > $charLen) {
                throw new \ValueError('mb_strimwidth(): Argument #2 ($start) is out of range');
            }
            $string = self::substr($string, $from, null, $encoding);
        }

        $totalWidth = self::strwidth($string, $encoding);
        if ($width < 0) {
            $width = $totalWidth + $width;
            if ($width < 0) {
                throw new \ValueError('mb_strimwidth(): Argument #3 ($width) is out of range');
            }
        }
        if ($totalWidth <= $width) {
            return $string;
        }

        $markerWidth = '' !== $trimmarker ? self::strwidth($trimmarker, $encoding) : 0;
        if ('' !== $trimmarker && $width <= $markerWidth) {
            return $trimmarker;
        }

        $contentWidth = $width - $markerWidth;
        if ('ASCII' === $encoding || '8BIT' === $encoding) {
            return self::trimSingleByteToWidth($string, $contentWidth).$trimmarker;
        }

        return self::trimUtf8ToWidth($string, $contentWidth).$trimmarker;
    }

    /**
     * mb_str_pad() — multibyte-aware str_pad (php-src ext/mbstring/mbstring.c; #6081).
     */
    public static function strPad(
        string $input,
        int $padLength,
        string $padString = ' ',
        int $padType = 1,
        string $encoding = 'UTF-8'
    ): string {
        self::assertSubstrCountEncoding($encoding, 'mb_str_pad');
        $inputLength = 'UTF-8' === $encoding
            ? VmString::utf8CharLength($input)
            : VmString::byteLength($input);
        if ($padLength < 0 || $padLength <= $inputLength) {
            return $input;
        }
        if ('' === $padString) {
            throw new \ValueError('mb_str_pad(): Argument #3 ($pad_string) must be a non-empty string');
        }
        $padUnitLength = 'UTF-8' === $encoding
            ? VmString::utf8CharLength($padString)
            : VmString::byteLength($padString);
        if (0 === $padUnitLength) {
            throw new \ValueError('mb_str_pad(): Argument #3 ($pad_string) must be a non-empty string');
        }
        if ($padType < 0 || $padType > 2) {
            throw new \ValueError(
                'mb_str_pad(): Argument #4 ($pad_type) must be STR_PAD_LEFT, STR_PAD_RIGHT, or STR_PAD_BOTH'
            );
        }

        $numPadUnits = $padLength - $inputLength;
        if (1 === $padType) {
            $leftPad = 0;
            $rightPad = $numPadUnits;
        } elseif (0 === $padType) {
            $leftPad = $numPadUnits;
            $rightPad = 0;
        } else {
            $leftPad = intdiv($numPadUnits, 2);
            $rightPad = $numPadUnits - $leftPad;
        }

        if ('UTF-8' === $encoding) {
            return self::repeatUtf8PadString($padString, $padUnitLength, $leftPad)
                .$input
                .self::repeatUtf8PadString($padString, $padUnitLength, $rightPad);
        }

        return self::repeatBytePadString($padString, $padUnitLength, $leftPad)
            .$input
            .self::repeatBytePadString($padString, $padUnitLength, $rightPad);
    }

    private static function repeatUtf8PadString(string $padString, int $padCharLength, int $charLength): string
    {
        if ($charLength <= 0) {
            return '';
        }
        $fullCopies = intdiv($charLength, $padCharLength);
        $remainder = $charLength % $padCharLength;
        $result = \str_repeat($padString, $fullCopies);
        if ($remainder > 0) {
            $result .= VmString::utf8CharSubstr($padString, 0, $remainder);
        }

        return $result;
    }

    private static function repeatBytePadString(string $padString, int $padByteLength, int $byteLength): string
    {
        if ($byteLength <= 0) {
            return '';
        }
        $fullCopies = intdiv($byteLength, $padByteLength);
        $remainder = $byteLength % $padByteLength;
        $result = \str_repeat($padString, $fullCopies);
        if ($remainder > 0) {
            $result .= VmString::byteSlice($padString, 0, $remainder);
        }

        return $result;
    }

    /**
     * mb_strcut() — byte-oriented slice aligned to character boundaries (php-src mb_strcut; #4573).
     *
     * $from and $length are measured in bytes (not codepoints, unlike mb_substr).
     */
    public static function strcut(
        string $string,
        int $from,
        ?int $length = null,
        string $encoding = 'UTF-8'
    ): string {
        self::assertSubstrCountEncoding($encoding, 'mb_strcut');
        $byteLen = VmString::byteLength($string);
        if (null === $length) {
            $length = $byteLen;
        }
        if ($from < 0) {
            $from = $byteLen + $from;
            if ($from < 0) {
                $from = 0;
            }
        }
        if ($length < 0) {
            $length = ($byteLen - $from) + $length;
            if ($length < 0) {
                $length = 0;
            }
        }
        if ($from > $byteLen || 0 === $length) {
            return '';
        }
        if ('ASCII' === $encoding || '8BIT' === $encoding) {
            if ($length > $byteLen - $from) {
                $length = $byteLen - $from;
            }

            return VmString::byteSlice($string, $from, $length);
        }

        return self::utf8ByteSafeCut($string, $from, $length);
    }

    /** UTF-8 byte cut with character-boundary alignment (php-src ext/mbstring/mbstring.c mb_strcut). */
    private static function utf8ByteSafeCut(string $string, int $from, int $length): string
    {
        $byteLen = VmString::byteLength($string);
        $start = self::utf8AlignByteStart($string, $from, $byteLen);
        if ($start >= $byteLen) {
            return '';
        }
        if ($length >= $byteLen - $start) {
            return VmString::byteSlice($string, $start, $byteLen - $start);
        }
        $end = self::utf8AlignByteEnd($string, $start, $start + $length, $byteLen);

        return VmString::byteSlice($string, $start, $end - $start);
    }

    private static function utf8AlignByteStart(string $string, int $from, int $byteLen): int
    {
        $p = 0;
        $lastWidth = 1;
        while ($p < $from && $p < $byteLen) {
            $lastWidth = VmString::utf8CharByteWidth($string, $p);
            $p += $lastWidth;
        }
        if ($p > $from) {
            $p -= $lastWidth;
        }

        return $p;
    }

    private static function utf8AlignByteEnd(
        string $string,
        int $start,
        int $target,
        int $byteLen
    ): int {
        $p = $start;
        $lastWidth = 1;
        while ($p < $target && $p < $byteLen) {
            $lastWidth = VmString::utf8CharByteWidth($string, $p);
            $p += $lastWidth;
        }
        if ($p > $target) {
            $p -= $lastWidth;
        }

        return $p;
    }

    public static function strtolower(string $string, string $encoding = 'UTF-8'): string
    {
        return self::convertCase($string, MbstringConstants::MB_CASE_LOWER, $encoding);
    }

    public static function strtoupper(string $string, string $encoding = 'UTF-8'): string
    {
        return self::convertCase($string, MbstringConstants::MB_CASE_UPPER, $encoding);
    }

    public static function coerceStartArg(Variable $var, string $function, int $argIndex = 1): int
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($start) must be of type int, %s given',
                $function,
                $argIndex + 1,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($start) must be of type int, %s given',
                $function,
                $argIndex + 1,
                self::typeLabel($var)
            ));
        }

        return $var->toInt();
    }

    public static function coerceLengthArg(Variable $var, string $function, int $argIndex = 2): int
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($length) must be of type int, %s given',
                $function,
                $argIndex + 1,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($length) must be of type int, %s given',
                $function,
                $argIndex + 1,
                self::typeLabel($var)
            ));
        }

        return $var->toInt();
    }

    public static function coerceOptionalLengthArg(Variable $var, string $function, int $argIndex = 2): ?int
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }

        return self::coerceLengthArg($var, $function, $argIndex);
    }

    /**
     * @return string|false
     */
    public static function strrichr(string $haystack, string $needle, bool $part = false, string $encoding = 'UTF-8')
    {
        self::assertSearchEncoding($encoding);
        $lowerHay = self::convertCase($haystack, MbstringConstants::MB_CASE_LOWER, $encoding);
        $lowerNeedle = self::convertCase($needle, MbstringConstants::MB_CASE_LOWER, $encoding);
        $pos = self::utf8Strrpos($lowerHay, $lowerNeedle, 0, false, $encoding, 'mb_strrichr');
        if (false === $pos) {
            return false;
        }
        if ($part) {
            return VmString::utf8CharSubstr($haystack, 0, $pos);
        }

        return VmString::utf8CharSubstr(
            $haystack,
            $pos,
            VmString::utf8CharLength($haystack) - $pos
        );
    }

    /**
     * @return int|false
     */
    private static function utf8Strpos(
        string $haystack,
        string $needle,
        int $offset,
        bool $caseInsensitive,
        string $encoding,
        string $function
    ) {
        self::assertSearchEncoding($encoding);
        if ($caseInsensitive) {
            $haystack = self::convertCase($haystack, MbstringConstants::MB_CASE_LOWER, $encoding);
            $needle = self::convertCase($needle, MbstringConstants::MB_CASE_LOWER, $encoding);
        }
        $hayLen = VmString::utf8CharLength($haystack);
        $needleLen = VmString::utf8CharLength($needle);
        $offset = self::normalizeCharOffset($hayLen, $offset, $function);
        if (0 === $needleLen) {
            return $offset;
        }
        for ($pos = $offset; $pos <= $hayLen - $needleLen; ++$pos) {
            if (VmString::utf8CharSubstr($haystack, $pos, $needleLen) === $needle) {
                return $pos;
            }
        }

        return false;
    }

    /**
     * @return int|false
     */
    private static function utf8Strrpos(
        string $haystack,
        string $needle,
        int $offset,
        bool $caseInsensitive,
        string $encoding,
        string $function
    ) {
        self::assertSearchEncoding($encoding);
        if ($caseInsensitive) {
            $haystack = self::convertCase($haystack, MbstringConstants::MB_CASE_LOWER, $encoding);
            $needle = self::convertCase($needle, MbstringConstants::MB_CASE_LOWER, $encoding);
        }
        $hayLen = VmString::utf8CharLength($haystack);
        $needleLen = VmString::utf8CharLength($needle);
        $minStart = 0;
        $maxStart = $hayLen - $needleLen;
        if ($offset < 0) {
            $maxStart = $hayLen + $offset;
            if ($maxStart < 0) {
                throw new \ValueError(sprintf(
                    '%s(): Argument #3 ($offset) must be contained in argument #1 ($haystack)',
                    $function
                ));
            }
            if (0 === $needleLen) {
                return $maxStart;
            }
            $maxStart -= $needleLen;
        } else {
            $minStart = $offset;
        }
        if (0 === $needleLen) {
            return $hayLen;
        }
        if ($minStart > $maxStart) {
            return false;
        }
        for ($pos = $maxStart; $pos >= $minStart; --$pos) {
            if (VmString::utf8CharSubstr($haystack, $pos, $needleLen) === $needle) {
                return $pos;
            }
        }

        return false;
    }

    private static function normalizeCharOffset(int $hayLen, int $offset, string $function): int
    {
        if ($offset < 0) {
            $offset += $hayLen;
        }
        if ($offset < 0 || $offset > $hayLen) {
            throw new \ValueError(sprintf(
                '%s(): Argument #3 ($offset) must be contained in argument #1 ($haystack)',
                $function
            ));
        }

        return $offset;
    }

    private static function assertSearchEncoding(string $encoding): void
    {
        self::assertSubstrCountEncoding($encoding, 'mbstring search');
    }

    public static function assertSubstrCountEncoding(string $encoding, string $context = 'mb_substr_count'): void
    {
        if ('UTF-8' !== $encoding && 'ASCII' !== $encoding && '8BIT' !== $encoding) {
            throw new \LogicException(
                $context.' requires mbstring for encoding '.$encoding.' in this compiler build'
            );
        }
    }

    /**
     * @return array<int, mixed>|string|int|null
     */
    public static function coerceCheckEncodingValueArg(
        Variable $var,
        string $function,
        int $argIndex = 0
    ): array|string|int|null {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($value) must be of type array|string|null, %s given',
                $function,
                $argIndex + 1,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_STRING === $var->type) {
            return $var->toString();
        }
        if (Variable::TYPE_INTEGER === $var->type) {
            return $var->toInt();
        }
        if (Variable::TYPE_ARRAY === $var->type) {
            $out = [];
            foreach ($var->toArray()->iterateKeyed(true) as [, $elem]) {
                $elem = $elem->resolveIndirect();
                if (Variable::TYPE_OBJECT === $elem->type) {
                    throw new \LogicException(
                        $function.'(): array value contains object; use checkEncodingForVariable()'
                    );
                }
                $out[] = self::checkEncodingScalarToPhp($elem);
            }

            return $out;
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($value) must be of type array|string|null, %s given',
                $function,
                $argIndex + 1,
                $var->toObject()->class->name
            ));
        }

        throw new \TypeError(sprintf(
            '%s(): Argument #%d ($value) must be of type array|string|null, %s given',
            $function,
            $argIndex + 1,
            self::typeLabel($var)
        ));
    }

    public static function checkEncodingForVariable(?Variable $valueVar, ?string $encoding = null): bool
    {
        if (null === $valueVar) {
            return self::checkEncoding(null, $encoding);
        }
        $var = $valueVar->resolveIndirect();
        if (Variable::TYPE_ARRAY === $var->type) {
            foreach ($var->toArray()->iterateKeyed(true) as [, $elem]) {
                if (Variable::TYPE_OBJECT === $elem->resolveIndirect()->type) {
                    return false;
                }
            }
        }

        return self::checkEncoding(
            self::coerceCheckEncodingValueArg($valueVar, 'mb_check_encoding', 0),
            $encoding
        );
    }

    /**
     * @param array<int, mixed>|string|int|null $value
     */
    public static function checkEncoding(array|string|int|null $value = null, ?string $encoding = null): bool
    {
        $encoding = null === $encoding ? 'UTF-8' : $encoding;
        self::assertCheckEncodingName($encoding);

        if (null === $value) {
            return true;
        }
        if (\is_int($value)) {
            $value = (string) $value;
        }
        if (\is_string($value)) {
            return self::isValidInEncoding($value, $encoding);
        }

        foreach ($value as $item) {
            if (\is_object($item)) {
                return false;
            }
            if (\is_int($item)) {
                $item = (string) $item;
            }
            if (!\is_string($item) || !self::isValidInEncoding($item, $encoding)) {
                return false;
            }
        }

        return true;
    }

    public static function assertCheckEncodingName(string $encoding): void
    {
        if (null === CharsetEngine::parseEncodingSpec($encoding)) {
            throw new \ValueError(sprintf(
                'mb_check_encoding(): Argument #2 ($encoding) must be a valid encoding, "%s" given',
                $encoding
            ));
        }
    }

    private static function isValidInEncoding(string $value, string $encoding): bool
    {
        $canonical = CharsetEngine::canonicalize($encoding) ?? $encoding;
        if ('UTF-8' === $canonical) {
            return VmString::isValidUtf8($value);
        }
        if ('ASCII' === $canonical || '8BIT' === $canonical) {
            return true;
        }

        throw new \LogicException(
            'mb_check_encoding() requires mbstring for encoding '.$encoding.' in this compiler build'
        );
    }

    private static function isValidUtf8(string $value): bool
    {
        $len = \strlen($value);
        for ($i = 0; $i < $len; ) {
            $need = 0;
            if (!self::utf8SequenceValidAt($value, $len, $i, $need)) {
                return false;
            }
            $i += $need + 1;
        }

        return true;
    }

    /**
     * mb_scrub() — replace invalid byte sequences (php-src ext/mbstring/mbstring.c; PHP 8.4, #6050).
     */
    public static function scrub(string $value, ?string $encoding = null): string
    {
        $encoding = null === $encoding ? 'UTF-8' : $encoding;
        self::assertScrubEncodingName($encoding);
        $canonical = self::canonicalScrubEncoding($encoding);
        if ('UTF-8' === $canonical) {
            return self::scrubUtf8($value);
        }
        if ('ASCII' === $canonical) {
            return self::scrubAscii($value);
        }
        if ('8BIT' === $canonical) {
            return $value;
        }

        throw new \LogicException(
            'mb_scrub() requires mbstring for encoding '.$encoding.' in this compiler build'
        );
    }

    public static function assertScrubEncodingName(string $encoding): void
    {
        if (null !== self::canonicalScrubEncoding($encoding)) {
            return;
        }
        throw new \ValueError(\sprintf(
            'mb_scrub(): Argument #2 ($encoding) must be a valid encoding, "%s" given',
            $encoding
        ));
    }

    private static function canonicalScrubEncoding(string $encoding): ?string
    {
        $upper = strtoupper($encoding);
        if ('UTF-8' === $upper || 'UTF8' === $upper) {
            return 'UTF-8';
        }
        if ('ASCII' === $upper) {
            return 'ASCII';
        }
        if ('8BIT' === $upper || 'BINARY' === $upper) {
            return '8BIT';
        }

        return CharsetEngine::canonicalize($encoding);
    }

    private static function scrubAscii(string $value): string
    {
        $out = '';
        $len = \strlen($value);
        for ($i = 0; $i < $len; ++$i) {
            $byte = \ord($value[$i]);
            $out .= $byte < 0x80 ? $value[$i] : '?';
        }

        return $out;
    }

    private static function scrubUtf8(string $value): string
    {
        $out = '';
        $len = \strlen($value);
        for ($i = 0; $i < $len; ) {
            $byte = \ord($value[$i]);
            if ($byte < 0x80) {
                $out .= $value[$i];
                ++$i;
                continue;
            }
            $need = 0;
            if (!self::utf8SequenceValidAt($value, $len, $i, $need)) {
                $out .= '?';
                ++$i;
                continue;
            }
            $out .= \substr($value, $i, $need + 1);
            $i += $need + 1;
        }

        return $out;
    }

    /**
     * @param-out int $need continuation byte count when lead byte is multi-byte
     */
    private static function utf8SequenceValidAt(string $value, int $len, int $i, ?int &$need = null): bool
    {
        $byte = \ord($value[$i]);
        if ($byte < 0x80) {
            $need = 0;

            return true;
        }
        if (($byte & 0xE0) === 0xC0) {
            $need = 1;
            $min = 0x80;
        } elseif (($byte & 0xF0) === 0xE0) {
            $need = 2;
            $min = 0x800;
        } elseif (($byte & 0xF8) === 0xF0) {
            $need = 3;
            $min = 0x10000;
        } else {
            $need = 0;

            return false;
        }
        if ($i + $need >= $len) {
            return false;
        }
        $cp = $byte & (0xFF >> (2 + $need));
        for ($j = 1; $j <= $need; ++$j) {
            $next = \ord($value[$i + $j]);
            if (($next & 0xC0) !== 0x80) {
                return false;
            }
            $cp = ($cp << 6) | ($next & 0x3F);
        }
        if ($cp < $min || ($cp >= 0xD800 && $cp <= 0xDFFF)) {
            return false;
        }

        return true;
    }

    /**
     * @return string|int|float|bool|null
     */
    private static function checkEncodingScalarToPhp(Variable $var): string|int|float|bool|null
    {
        return match ($var->type) {
            Variable::TYPE_NULL => null,
            Variable::TYPE_BOOLEAN => $var->toBool(),
            Variable::TYPE_INTEGER => $var->toInt(),
            Variable::TYPE_FLOAT => $var->toFloat(),
            Variable::TYPE_STRING => $var->toString(),
            default => null,
        };
    }

    private static function typeLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOL => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_DOUBLE => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => $var->toObject()->class->name,
            default => 'mixed',
        };
    }

    public static function runTrimBuiltin(Frame $frame, string $function, int $mode): void
    {
        if (!isset($frame->calledArgs[0])) {
            throw new \ArgumentCountError(sprintf(
                '%s() expects at least 1 argument, %d given',
                $function,
                \count($frame->calledArgs)
            ));
        }
        foreach (array_keys($frame->calledArgs) as $idx) {
            if ($idx < 0 || $idx > 2) {
                throw new \ArgumentCountError(sprintf(
                    '%s() expects at most 3 arguments, %d given',
                    $function,
                    \count($frame->calledArgs)
                ));
            }
        }
        $source = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            $function,
            0,
            'string'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $what = null;
        if (isset($frame->calledArgs[1])) {
            $whatVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $whatVar->type) {
                $what = VmString::coerceStringBuiltinArg(
                    $frame->calledArgs[1],
                    $function,
                    1,
                    'characters'
                );
            }
        }
        $encoding = isset($frame->calledArgs[2])
            ? self::coerceEncodingArg($frame->calledArgs[2], $function, 2)
            : 'UTF-8';
        $frame->returnVar->string(self::trimString($source, $what, $encoding, $mode));
    }

    public static function trimString(string $source, ?string $what, string $encoding, int $mode): string
    {
        self::assertTrimEncoding($encoding);
        if (null === $what) {
            $trimSet = self::defaultTrimSet();
        } elseif ('' === $what) {
            return $source;
        } else {
            $trimSet = self::trimSetFromWhat($what, $encoding);
        }
        if ('UTF-8' === $encoding) {
            return self::trimUtf8($source, $trimSet, $mode);
        }

        return self::trimSingleByte($source, $trimSet, $mode);
    }

    /**
     * @return array<int, true>
     */
    private static function defaultTrimSet(): array
    {
        $set = [];
        foreach (self::DEFAULT_TRIM_CODEPOINTS as $cp) {
            $set[$cp] = true;
        }

        return $set;
    }

    /**
     * @return array<int, true>
     */
    private static function trimSetFromWhat(string $what, string $encoding): array
    {
        $set = [];
        foreach (self::codepointsInString($what, $encoding) as $cp) {
            $set[$cp] = true;
        }

        return $set;
    }

    /**
     * @return list<int>
     */
    private static function codepointsInString(string $string, string $encoding): array
    {
        if ('UTF-8' === $encoding) {
            $out = [];
            $charLen = VmString::utf8CharLength($string);
            for ($i = 0; $i < $charLen; ++$i) {
                $out[] = self::decodeUtf8Char(VmString::utf8CharSubstr($string, $i, 1));
            }

            return $out;
        }
        $out = [];
        $byteLen = \strlen($string);
        for ($i = 0; $i < $byteLen; ++$i) {
            $out[] = \ord($string[$i]);
        }

        return $out;
    }

    /**
     * @param array<int, true> $trimSet
     */
    private static function trimUtf8(string $source, array $trimSet, int $mode): string
    {
        $charLen = VmString::utf8CharLength($source);
        if (0 === $charLen) {
            return '';
        }
        $left = 0;
        $right = 0;
        $currentMode = $mode;
        for ($i = 0; $i < $charLen; ++$i) {
            $cp = self::decodeUtf8Char(VmString::utf8CharSubstr($source, $i, 1));
            if (isset($trimSet[$cp])) {
                if ($currentMode & self::MB_LTRIM) {
                    ++$left;
                }
                if ($currentMode & self::MB_RTRIM) {
                    ++$right;
                }
            } else {
                $currentMode &= ~self::MB_LTRIM;
                if ($currentMode & self::MB_RTRIM) {
                    $right = 0;
                }
            }
        }
        if (0 === $left && 0 === $right) {
            return $source;
        }

        return VmString::utf8CharSubstr($source, $left, $charLen - $left - $right);
    }

    /**
     * @param array<int, true> $trimSet
     */
    private static function trimSingleByte(string $source, array $trimSet, int $mode): string
    {
        $byteLen = \strlen($source);
        if (0 === $byteLen) {
            return '';
        }
        $left = 0;
        $right = 0;
        $currentMode = $mode;
        for ($i = 0; $i < $byteLen; ++$i) {
            $cp = \ord($source[$i]);
            if (isset($trimSet[$cp])) {
                if ($currentMode & self::MB_LTRIM) {
                    ++$left;
                }
                if ($currentMode & self::MB_RTRIM) {
                    ++$right;
                }
            } else {
                $currentMode &= ~self::MB_LTRIM;
                if ($currentMode & self::MB_RTRIM) {
                    $right = 0;
                }
            }
        }
        if (0 === $left && 0 === $right) {
            return $source;
        }

        return \substr($source, $left, $byteLen - $left - $right);
    }

    private static function trimUtf8ToWidth(string $string, int $contentWidth): string
    {
        if ($contentWidth <= 0) {
            return '';
        }
        $used = 0;
        $out = '';
        $charLen = VmString::utf8CharLength($string);
        for ($i = 0; $i < $charLen; ++$i) {
            $char = VmString::utf8CharSubstr($string, $i, 1);
            $charWidth = EastAsianWidthTable::characterWidth(self::decodeUtf8Char($char));
            if ($used + $charWidth > $contentWidth) {
                break;
            }
            $out .= $char;
            $used += $charWidth;
        }

        return $out;
    }

    private static function trimSingleByteToWidth(string $string, int $contentWidth): string
    {
        if ($contentWidth <= 0) {
            return '';
        }
        $byteLen = VmString::byteLength($string);
        if ($contentWidth >= $byteLen) {
            return $string;
        }

        return VmString::byteSlice($string, 0, $contentWidth);
    }

    private static function decodeUtf8Char(string $char): int
    {
        $len = \strlen($char);
        if (0 === $len) {
            return 0;
        }
        $b0 = \ord($char[0]);
        if ($b0 < 0x80) {
            return $b0;
        }
        if ($len >= 2 && ($b0 & 0xE0) === 0xC0) {
            return (($b0 & 0x1F) << 6) | (\ord($char[1]) & 0x3F);
        }
        if ($len >= 3 && ($b0 & 0xF0) === 0xE0) {
            return (($b0 & 0x0F) << 12) | ((\ord($char[1]) & 0x3F) << 6) | (\ord($char[2]) & 0x3F);
        }
        if ($len >= 4 && ($b0 & 0xF8) === 0xF0) {
            return (($b0 & 0x07) << 18) | ((\ord($char[1]) & 0x3F) << 12)
                | ((\ord($char[2]) & 0x3F) << 6) | (\ord($char[3]) & 0x3F);
        }

        return $b0;
    }

    /** UTF-8 single-character decode for mbstring helpers (#13099). */
    public static function utf8CharToCodepoint(string $char): int
    {
        return self::decodeUtf8Char($char);
    }

    private static function assertTrimEncoding(string $encoding): void
    {
        if ('UTF-8' !== $encoding && 'ASCII' !== $encoding && '8BIT' !== $encoding) {
            throw new \LogicException(
                'mb_trim() requires mbstring for encoding '.$encoding.' in this compiler build'
            );
        }
    }

    /**
     * @return list<int>
     */
    public static function coerceConvMapArg(Variable $var, string $function): array
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(sprintf(
                '%s(): Argument #2 ($map) must be of type array, %s given',
                $function,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_ARRAY !== $var->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #2 ($map) must be of type array, %s given',
                $function,
                self::typeLabel($var)
            ));
        }

        $elems = [];
        foreach ($var->toArray()->iterateKeyed(true) as [, $elem]) {
            $elem = $elem->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $elem->type) {
                throw new \ValueError(sprintf(
                    '%s(): Argument #2 ($map) must only be composed of values of type int',
                    $function
                ));
            }
            $elems[] = $elem->toInt();
        }
        if (0 !== (\count($elems) % 4)) {
            throw new \ValueError(sprintf(
                '%s(): Argument #2 ($map) must have a multiple of 4 elements',
                $function
            ));
        }

        return $elems;
    }

    public static function resolveNumericEntityEncoding(
        ?string $encoding,
        string $function,
        int $argIndex = 2
    ): string {
        $encoding = null === $encoding ? 'UTF-8' : $encoding;
        if (null === CharsetEngine::parseEncodingSpec($encoding)) {
            throw new \ValueError(sprintf(
                '%s(): Argument #%d ($encoding) must be a valid encoding, "%s" given',
                $function,
                $argIndex + 1,
                $encoding
            ));
        }

        return $encoding;
    }

    /**
     * @param list<int> $convmap
     */
    public static function encodeNumericEntity(
        string $str,
        array $convmap,
        string $encoding = 'UTF-8',
        bool $isHex = false
    ): string {
        self::assertNumericEntityEncoding($encoding);
        if ('UTF-8' === $encoding) {
            return self::encodeNumericEntityUtf8($str, $convmap, $isHex);
        }

        return self::encodeNumericEntitySingleByte($str, $convmap, $isHex);
    }

    /**
     * @param list<int> $convmap
     */
    public static function decodeNumericEntity(string $str, array $convmap, string $encoding = 'UTF-8'): string
    {
        self::assertNumericEntityEncoding($encoding);
        if ('UTF-8' === $encoding) {
            return self::decodeNumericEntityUtf8($str, $convmap);
        }

        return self::decodeNumericEntitySingleByte($str, $convmap);
    }

    /**
     * @param list<int> $convmap
     */
    private static function numericEntityConvert(int $wchar, array $convmap, int &$entityNum): bool
    {
        $count = \count($convmap);
        for ($i = 0; $i < $count; $i += 4) {
            $loCode = $convmap[$i];
            $hiCode = $convmap[$i + 1];
            $offset = $convmap[$i + 2];
            $mask = $convmap[$i + 3];
            if ($wchar >= $loCode && $wchar <= $hiCode) {
                $entityNum = ($wchar + $offset) & $mask;

                return true;
            }
        }

        return false;
    }

    /**
     * @param list<int> $convmap
     */
    private static function numericEntityDeconvert(int $number, array $convmap, int &$codepoint): bool
    {
        $count = \count($convmap);
        for ($i = 0; $i < $count; $i += 4) {
            $loCode = $convmap[$i];
            $hiCode = $convmap[$i + 1];
            $offset = $convmap[$i + 2];
            $codepoint = $number - $offset;
            if ($codepoint >= $loCode && $codepoint <= $hiCode) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<int> $convmap
     */
    private static function encodeNumericEntityUtf8(string $str, array $convmap, bool $isHex): string
    {
        $out = '';
        foreach (self::codepointsInString($str, 'UTF-8') as $wchar) {
            $entityNum = 0;
            if (self::numericEntityConvert($wchar, $convmap, $entityNum)) {
                $out .= '&#';
                if ($isHex) {
                    $out .= 'x';
                }
                if (0 === $entityNum) {
                    $out .= '0';
                } elseif ($isHex) {
                    $out .= strtoupper(dechex($entityNum));
                } else {
                    $out .= (string) $entityNum;
                }
                $out .= ';';
            } else {
                $out .= self::encodeUtf8Codepoint($wchar);
            }
        }

        return $out;
    }

    /**
     * @param list<int> $convmap
     */
    private static function encodeNumericEntitySingleByte(string $str, array $convmap, bool $isHex): string
    {
        $out = '';
        $byteLen = \strlen($str);
        for ($i = 0; $i < $byteLen; ++$i) {
            $wchar = \ord($str[$i]);
            $entityNum = 0;
            if (self::numericEntityConvert($wchar, $convmap, $entityNum)) {
                $out .= '&#';
                if ($isHex) {
                    $out .= 'x';
                }
                if (0 === $entityNum) {
                    $out .= '0';
                } elseif ($isHex) {
                    $out .= strtoupper(dechex($entityNum));
                } else {
                    $out .= (string) $entityNum;
                }
                $out .= ';';
            } else {
                $out .= $str[$i];
            }
        }

        return $out;
    }

    /**
     * @param list<int> $convmap
     */
    private static function decodeNumericEntityUtf8(string $str, array $convmap): string
    {
        $len = \strlen($str);
        $out = '';
        $i = 0;
        while ($i < $len) {
            if ('&' !== $str[$i]) {
                $out .= $str[$i];
                ++$i;
                continue;
            }
            $replacement = '';
            $end = $i;
            $consumed = self::tryDecodeNumericEntityAt($str, $i, $convmap, $replacement, $end);
            if ($consumed) {
                $out .= $replacement;
                $i = $end;
                continue;
            }
            $out .= $str[$i];
            ++$i;
        }

        return $out;
    }

    /**
     * @param list<int> $convmap
     */
    private static function decodeNumericEntitySingleByte(string $str, array $convmap): string
    {
        $decoded = self::decodeNumericEntityUtf8($str, $convmap);
        $out = '';
        foreach (self::codepointsInString($decoded, 'UTF-8') as $cp) {
            if ($cp > 0xFF) {
                $out .= '?';
            } else {
                $out .= \chr($cp);
            }
        }

        return $out;
    }

    /**
     * @param list<int> $convmap
     */
    private static function tryDecodeNumericEntityAt(
        string $str,
        int $pos,
        array $convmap,
        ?string &$replacement,
        int &$end
    ): bool {
        $len = \strlen($str);
        if ($pos + 2 >= $len || '#' !== $str[$pos + 1]) {
            return false;
        }

        if ('x' === $str[$pos + 2] || 'X' === $str[$pos + 2]) {
            $digitStart = $pos + 3;
            $digitEnd = $digitStart;
            while ($digitEnd < $len && ctype_xdigit($str[$digitEnd])) {
                ++$digitEnd;
            }
            $entityLen = $digitEnd - $pos;
            $digitLen = $digitEnd - $digitStart;
            if ($digitLen < 1 || $digitLen > 8 || $entityLen < 4 || $entityLen > 11) {
                return false;
            }
            $value = (int) \hexdec(substr($str, $digitStart, $digitLen));
            $codepoint = 0;
            if (!self::numericEntityDeconvert($value, $convmap, $codepoint)) {
                return false;
            }
            $replacement = self::encodeUtf8Codepoint($codepoint);
            $end = $digitEnd;
            if ($end < $len && ';' === $str[$end]) {
                ++$end;
            }

            return true;
        }

        $digitStart = $pos + 2;
        $digitEnd = $digitStart;
        while ($digitEnd < $len && $str[$digitEnd] >= '0' && $str[$digitEnd] <= '9') {
            ++$digitEnd;
        }
        $entityLen = $digitEnd - $pos;
        $digitLen = $digitEnd - $digitStart;
        if ($digitLen < 1 || $digitLen > 10 || $entityLen < 3 || $entityLen > 12) {
            return false;
        }
        $value = 0;
        for ($k = $digitStart; $k < $digitEnd; ++$k) {
            if ($value > 0x19999999) {
                return false;
            }
            $value = ($value * 10) + (\ord($str[$k]) - 48);
        }
        $codepoint = 0;
        if (!self::numericEntityDeconvert($value, $convmap, $codepoint)) {
            return false;
        }
        $replacement = self::encodeUtf8Codepoint($codepoint);
        $end = $digitEnd;
        if ($end < $len && ';' === $str[$end]) {
            ++$end;
        }

        return true;
    }

    public static function encodeUtf8Codepoint(int $cp): string
    {
        if ($cp < 0x80) {
            return \chr($cp);
        }
        if ($cp < 0x800) {
            return \chr(0xC0 | ($cp >> 6)).\chr(0x80 | ($cp & 0x3F));
        }
        if ($cp < 0x10000) {
            return \chr(0xE0 | ($cp >> 12))
                .\chr(0x80 | (($cp >> 6) & 0x3F))
                .\chr(0x80 | ($cp & 0x3F));
        }

        return \chr(0xF0 | ($cp >> 18))
            .\chr(0x80 | (($cp >> 12) & 0x3F))
            .\chr(0x80 | (($cp >> 6) & 0x3F))
            .\chr(0x80 | ($cp & 0x3F));
    }

    private static function assertNumericEntityEncoding(string $encoding): void
    {
        if ('UTF-8' !== $encoding && 'ASCII' !== $encoding && '8BIT' !== $encoding) {
            throw new \LogicException(
                'mb_encode_numericentity()/mb_decode_numericentity() require mbstring for encoding '
                .$encoding.' in this compiler build'
            );
        }
    }

    /**
     * mb_encode_mimeheader() — RFC 2047 encoded-word headers (php-src ext/mbstring/mbstring.c; #6038).
     */
    public static function encodeMimeheader(
        string $str,
        string $charset = 'UTF-8',
        bool $base64 = true,
        string $linefeed = "\r\n",
        int $indent = 0
    ): string {
        if ('' === $str) {
            return '';
        }
        self::assertMimeHeaderCharset($charset);
        if ($indent < 0 || $indent >= 74) {
            $indent = 0;
        }
        if (self::mimeHeaderCanPassThrough($str)) {
            return $str;
        }

        $parts = self::mimeHeaderSplitSegments($str);
        $out = '';
        foreach ($parts as $part) {
            if ('ascii' === $part['type']) {
                $out .= $part['text'];
                continue;
            }
            if ('' !== $out && !str_ends_with($out, ' ')) {
                $out .= ' ';
            }
            $out .= self::mimeHeaderEncodeWord($part['text'], $charset, $base64);
        }

        return $out;
    }

    /**
     * mb_decode_mimeheader() — decode RFC 2047 encoded words (php-src ext/mbstring/mbstring.c; #6038).
     */
    public static function decodeMimeheader(string $str): string
    {
        if ('' === $str) {
            return '';
        }

        $len = \strlen($str);
        $out = '';
        $i = 0;
        while ($i < $len) {
            if ('=' === $str[$i] && ($i + 1) < $len && '?' === $str[$i + 1]) {
                $decoded = self::mimeHeaderDecodeWordAt($str, $i, $len);
                if (null !== $decoded) {
                    [$text, $next] = $decoded;
                    $out .= $text;
                    $i = $next;
                    while ($i < $len && self::mimeHeaderIsWhitespace($str[$i])) {
                        ++$i;
                    }
                    if ($i < $len && '=' === $str[$i] && ($i + 1) < $len && '?' === $str[$i + 1]) {
                        continue;
                    }
                    if ($i < $len) {
                        $out .= ' ';
                    }
                    continue;
                }
            }

            $start = $i;
            while ($i < $len) {
                if ('=' === $str[$i] && ($i + 1) < $len && '?' === $str[$i + 1]) {
                    break;
                }
                if ("\n" === $str[$i] || "\r" === $str[$i]) {
                    ++$i;
                    while ($i < $len && self::mimeHeaderIsWhitespace($str[$i])) {
                        ++$i;
                    }
                    if ($i < $len) {
                        $out .= ' ';
                    }
                    break;
                }
                ++$i;
            }
            if ($i > $start) {
                $out .= \substr($str, $start, $i - $start);
            }
        }

        return $out;
    }

    public static function coerceMimeHeaderTransferEncoding(Variable $var, string $function, int $argIndex): bool
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return true;
        }
        $name = VmString::coerceStringBuiltinArg($var, $function, $argIndex, 'transfer_encoding');
        if ('' === $name) {
            return true;
        }
        $flag = $name[0];

        return 'B' !== $flag && 'b' !== $flag ? false : true;
    }

    public static function coerceMimeHeaderLinefeed(Variable $var, string $function, int $argIndex): string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return "\r\n";
        }

        return VmString::coerceStringBuiltinArg($var, $function, $argIndex, 'linefeed');
    }

    public static function coerceMimeHeaderIndent(Variable $var, string $function, int $argIndex): int
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($indent) must be of type int, %s given',
                $function,
                $argIndex + 1,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($indent) must be of type int, %s given',
                $function,
                $argIndex + 1,
                self::typeLabel($var)
            ));
        }

        return $var->toInt();
    }

    private static function assertMimeHeaderCharset(string $charset): void
    {
        if ('UTF-8' !== $charset && 'ASCII' !== $charset && '8BIT' !== $charset) {
            throw new \ValueError(\sprintf(
                'mb_encode_mimeheader(): Argument #2 ($charset) is not a supported encoding, "%s" given',
                $charset
            ));
        }
        if ('ASCII' === $charset || '8BIT' === $charset) {
            return;
        }
    }

    private static function mimeHeaderCanPassThrough(string $str): bool
    {
        $checkingLeading = true;
        $len = \strlen($str);
        for ($i = 0; $i < $len; ++$i) {
            $byte = \ord($str[$i]);
            if ($checkingLeading && 0x20 === $byte) {
                continue;
            }
            $checkingLeading = false;
            if ($byte < 0x21 || $byte > 0x7E || 0x3D === $byte || 0x3F === $byte || 0x5F === $byte) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<array{type: 'ascii'|'encoded', text: string}>
     */
    private static function mimeHeaderSplitSegments(string $str): array
    {
        $parts = [];
        $len = \strlen($str);
        $i = 0;
        while ($i < $len) {
            $start = $i;
            while ($i < $len && self::mimeHeaderIsSafeAsciiByte($str[$i])) {
                ++$i;
            }
            if ($i > $start) {
                $parts[] = ['type' => 'ascii', 'text' => \substr($str, $start, $i - $start)];
            }
            if ($i >= $len) {
                break;
            }
            $start = $i;
            while ($i < $len && !self::mimeHeaderIsSafeAsciiByte($str[$i])) {
                $i += VmString::utf8CharByteWidth($str, $i);
            }
            $parts[] = ['type' => 'encoded', 'text' => \substr($str, $start, $i - $start)];
        }

        return $parts;
    }

    private static function mimeHeaderIsSafeAsciiByte(string $byte): bool
    {
        $ord = \ord($byte);

        return $ord >= 0x20 && $ord <= 0x7E && 0x3D !== $ord && 0x3F !== $ord && 0x5F !== $ord;
    }

    private static function mimeHeaderIsWhitespace(string $byte): bool
    {
        return ' ' === $byte || "\t" === $byte || "\r" === $byte || "\n" === $byte;
    }

    private static function mimeHeaderEncodeWord(string $text, string $charset, bool $base64): string
    {
        $mimeCharset = 'ASCII' === $charset || '8BIT' === $charset ? 'ISO-8859-1' : $charset;

        return $base64
            ? '=?'.$mimeCharset.'?B?'.\base64_encode($text).'?='
            : '=?'.$mimeCharset.'?Q?'.self::mimeHeaderQEncode($text).'?=';
    }

    private static function mimeHeaderQEncode(string $text): string
    {
        $out = '';
        $len = \strlen($text);
        for ($i = 0; $i < $len; ++$i) {
            $byte = $text[$i];
            $ord = \ord($byte);
            if ($ord >= 0x20 && $ord <= 0x7E && 0x3D !== $ord && 0x3F !== $ord && 0x5F !== $ord) {
                $out .= $byte;
                continue;
            }
            if (0x20 === $ord) {
                $out .= '_';
                continue;
            }
            $out .= \sprintf('=%02X', $ord);
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: int}|null
     */
    private static function mimeHeaderDecodeWordAt(string $str, int $pos, int $len): ?array
    {
        if (($pos + 5) >= $len || '=' !== $str[$pos] || '?' !== $str[$pos + 1]) {
            return null;
        }
        $charsetEnd = \strpos($str, '?', $pos + 2);
        if (false === $charsetEnd || ($charsetEnd + 2) >= $len) {
            return null;
        }
        $encoding = $str[$charsetEnd + 1];
        if ('?' !== $str[$charsetEnd + 2]) {
            return null;
        }
        $dataStart = $charsetEnd + 3;
        $dataEnd = \strpos($str, '?=', $dataStart);
        if (false === $dataEnd) {
            if ($len > $dataStart && '?' === $str[$len - 1]) {
                $dataEnd = $len - 1;
                $next = $len;
            } else {
                return null;
            }
        } else {
            $next = $dataEnd + 2;
        }
        $payload = \substr($str, $dataStart, $dataEnd - $dataStart);
        $decoded = ('Q' === $encoding || 'q' === $encoding)
            ? self::mimeHeaderQDecode($payload)
            : self::mimeHeaderBase64Decode($payload);

        return [$decoded, $next];
    }

    private static function mimeHeaderBase64Decode(string $payload): string
    {
        $clean = \preg_replace('/[\r\n\t =]/', '', $payload);
        if (!\is_string($clean) || '' === $clean) {
            return '';
        }
        $decoded = \base64_decode($clean, true);

        return false === $decoded ? '' : $decoded;
    }

    private static function mimeHeaderQDecode(string $payload): string
    {
        $out = '';
        $len = \strlen($payload);
        for ($i = 0; $i < $len; ++$i) {
            $byte = $payload[$i];
            if ('_' === $byte) {
                $out .= ' ';
                continue;
            }
            if ('=' === $byte && ($i + 2) < $len) {
                $hex = \hexdec(\substr($payload, $i + 1, 2));
                $out .= \chr((int) $hex);
                $i += 2;
                continue;
            }
            $out .= $byte;
        }

        return $out;
    }

    /**
     * mb_split() — multibyte regex split (php-src ext/mbstring/php_mbregex.c; #13367).
     *
     * UTF-8 / ASCII via PCRE u-flag; Onig-specific patterns may differ from Zend.
     *
     * @return array<int, string>|false
     */
    public static function split(string $pattern, string $string, int $limit = -1): array|false
    {
        if (!self::checkEncoding($string, 'UTF-8')) {
            return false;
        }

        $regex = self::mbSplitRegex($pattern);
        if (null === $regex) {
            return false;
        }

        @preg_match($regex, '');
        if (PREG_NO_ERROR !== preg_last_error()) {
            return false;
        }

        $parts = preg_split($regex, $string, $limit > 0 ? $limit : -1);
        if (false === $parts) {
            return false;
        }

        return $parts;
    }

    public static function mbSplitRegexCompileError(string $pattern): ?string
    {
        $regex = self::mbSplitRegex($pattern);
        if (null === $regex) {
            return 'invalid pattern delimiter';
        }
        @preg_match($regex, '');

        return PREG_NO_ERROR === preg_last_error() ? null : preg_last_error_msg();
    }

    public static function warnMbSplitRegexFailure(Frame $frame, string $pattern): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $detail = self::mbSplitRegexCompileError($pattern) ?? 'invalid pattern';
        $frame->vmContext->errors->triggerErrorWithHandlerFirst(
            'mb_split(): mbregex compile err: '.$detail,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }

    private static function mbSplitRegex(string $pattern): ?string
    {
        if ('' === $pattern) {
            return null;
        }

        return '#'.$pattern.'#u';
    }
}
