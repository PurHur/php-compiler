<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * hebrev() — logical to visual Hebrew text (php-src ext/standard/string.c parity, #3450).
 *
 * Operates on ISO-8859-8 byte ranges (0xE0–0xFA); UTF-8 Hebrew passes through unchanged like Zend.
 */
final class VmHebrev
{
    private const HEB_BLOCK_TYPE_ENG = 1;

    private const HEB_BLOCK_TYPE_HEB = 2;

    public static function convert(string $str, int $maxCharsPerLine = 0): string
    {
        $strLen = \strlen($str);
        if (0 === $strLen) {
            return '';
        }

        /** @var list<string> */
        $hebBuf = \array_fill(0, $strLen, "\0");
        $target = $strLen - 1;

        $blockStart = 0;
        $blockEnd = 0;
        $tmp = 0;

        $blockType = self::isHeb(\ord($str[$tmp]))
            ? self::HEB_BLOCK_TYPE_HEB
            : self::HEB_BLOCK_TYPE_ENG;

        do {
            if (self::HEB_BLOCK_TYPE_HEB === $blockType) {
                while (
                    $blockEnd < $strLen - 1
                    && (
                        self::isHeb(\ord($str[$tmp + 1]))
                        || self::isBlank(\ord($str[$tmp + 1]))
                        || self::isPunct($str[$tmp + 1])
                        || "\n" === $str[$tmp + 1]
                    )
                ) {
                    ++$tmp;
                    ++$blockEnd;
                }
                for ($i = $blockStart + 1; $i <= $blockEnd + 1; ++$i) {
                    $hebBuf[$target] = self::swapHebPunct($str[$i - 1]);
                    --$target;
                }
                $blockType = self::HEB_BLOCK_TYPE_ENG;
            } else {
                while (
                    $blockEnd < $strLen - 1
                    && !self::isHeb(\ord($str[$tmp + 1]))
                    && "\n" !== $str[$tmp + 1]
                ) {
                    ++$tmp;
                    ++$blockEnd;
                }
                while (
                    $blockEnd > $blockStart
                    && (self::isBlank(\ord($str[$tmp])) || self::isPunct($str[$tmp]))
                    && '/' !== $str[$tmp]
                    && '-' !== $str[$tmp]
                ) {
                    --$tmp;
                    --$blockEnd;
                }
                for ($i = $blockEnd + 1; $i >= $blockStart + 1; --$i) {
                    $hebBuf[$target] = $str[$i - 1];
                    --$target;
                }
                $blockType = self::HEB_BLOCK_TYPE_HEB;
            }
            $blockStart = $blockEnd + 1;
        } while ($blockEnd < $strLen - 1);

        $begin = $end = $strLen - 1;
        $result = '';

        while (true) {
            $charCount = 0;
            while (
                (0 === $maxCharsPerLine || ($maxCharsPerLine > 0 && $charCount < $maxCharsPerLine))
                && $begin > 0
            ) {
                ++$charCount;
                --$begin;
                if (self::isNewline(\ord($hebBuf[$begin]))) {
                    while ($begin > 0 && self::isNewline(\ord($hebBuf[$begin - 1]))) {
                        --$begin;
                        ++$charCount;
                    }
                    break;
                }
            }

            if ($maxCharsPerLine >= 0 && $charCount === $maxCharsPerLine) {
                $newCharCount = $charCount;
                $newBegin = $begin;
                while ($newCharCount > 0) {
                    if (self::isBlank(\ord($hebBuf[$newBegin])) || self::isNewline(\ord($hebBuf[$newBegin]))) {
                        break;
                    }
                    ++$newBegin;
                    --$newCharCount;
                }
                if ($newCharCount > 0) {
                    $begin = $newBegin;
                }
            }

            $origBegin = $begin;

            if (self::isBlank(\ord($hebBuf[$begin]))) {
                $hebBuf[$begin] = "\n";
            }
            $lineBegin = $begin;
            while ($lineBegin <= $end && self::isNewline(\ord($hebBuf[$lineBegin]))) {
                ++$lineBegin;
            }
            for ($i = $lineBegin; $i <= $end; ++$i) {
                $result .= $hebBuf[$i];
            }
            for ($i = $origBegin; $i <= $end && self::isNewline(\ord($hebBuf[$i])); ++$i) {
                $result .= $hebBuf[$i];
            }
            $begin = $origBegin;

            if (0 === $begin) {
                break;
            }
            --$begin;
            $end = $begin;
        }

        return $result;
    }

    /**
     * hebrevc() — visual Hebrew with newline conversion (php-src string.c convert_newlines=1, #17183).
     */
    public static function convertWithNewlines(string $str, int $maxCharsPerLine = 0): string
    {
        $visual = self::convert($str, $maxCharsPerLine);
        if ('' === $visual) {
            return '';
        }

        return \str_replace("\n", " \n", $visual);
    }

    private static function isHeb(int $c): bool
    {
        return $c >= 224 && $c <= 250;
    }

    private static function isBlank(int $c): bool
    {
        return 32 === $c || 9 === $c;
    }

    private static function isNewline(int $c): bool
    {
        return 10 === $c || 13 === $c;
    }

    private static function isPunct(string $ch): bool
    {
        return '' !== $ch && \ctype_punct($ch);
    }

    private static function swapHebPunct(string $ch): string
    {
        return match ($ch) {
            '(' => ')',
            ')' => '(',
            '[' => ']',
            ']' => '[',
            '{' => '}',
            '}' => '{',
            '<' => '>',
            '>' => '<',
            '\\' => '/',
            '/' => '\\',
            default => $ch,
        };
    }
}
