<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for strip_tags() runtime (#9196, php-in-PHP).
 *
 * Logic mirrors {@see VmString::stripTags()} (php-src ext/standard/string.c — php_strip_tags_ex).
 * Self-contained so parseAndCompile() does not depend on compiling all of VmString.php.
 */
final class StripTagsJitHelper
{
    public static function stripTags(string $input, string $allowedMarkup): string
    {
        $allowed = '' === $allowedMarkup ? [] : self::parseAllowedTags($allowedMarkup);
        $out = '';
        $len = \strlen($input);
        $i = 0;
        while ($i < $len) {
            $ch = $input[$i];
            if ('<' !== $ch) {
                $out .= $ch;
                ++$i;
                continue;
            }
            if ($i + 3 < $len && '<!--' === \substr($input, $i, 4)) {
                $end = self::findSubstring($input, '-->', $i + 4);
                if (false !== $end) {
                    $i = $end + 3;
                    continue;
                }
            }
            if ($i + 1 < $len && '<?' === \substr($input, $i, 2)) {
                $end = self::findSubstring($input, '?>', $i + 2);
                if (false !== $end) {
                    $i = $end + 2;
                    continue;
                }
            }
            $gt = self::findSubstring($input, '>', $i + 1);
            if (false === $gt) {
                $out .= $ch;
                ++$i;
                continue;
            }
            $tagContent = \substr($input, $i + 1, $gt - $i - 1);
            $tagName = self::extractTagName($tagContent);
            if (null !== $tagName && [] !== $allowed && self::isTagAllowed($tagName, $allowed)) {
                $out .= \substr($input, $i, $gt - $i + 1);
            }
            $i = $gt + 1;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function parseAllowedTags(string $allowedTags): array
    {
        $tags = [];
        $len = \strlen($allowedTags);
        $i = 0;
        while ($i < $len) {
            if ('<' !== $allowedTags[$i]) {
                ++$i;
                continue;
            }
            $gt = self::findSubstring($allowedTags, '>', $i + 1);
            if (false === $gt) {
                break;
            }
            $name = self::extractTagName(\substr($allowedTags, $i + 1, $gt - $i - 1));
            if (null !== $name && '' !== $name) {
                $tags[] = $name;
            }
            $i = $gt + 1;
        }

        return $tags;
    }

    private static function extractTagName(string $tagContent): ?string
    {
        $len = \strlen($tagContent);
        $i = 0;
        while ($i < $len && self::isTagWhitespace($tagContent[$i])) {
            ++$i;
        }
        if ($i < $len && '/' === $tagContent[$i]) {
            ++$i;
        }
        if ($i >= $len) {
            return null;
        }
        $start = $i;
        while ($i < $len) {
            $ch = $tagContent[$i];
            if (self::isTagWhitespace($ch) || '>' === $ch || '/' === $ch) {
                break;
            }
            if (!self::isTagChar($ch)) {
                return null;
            }
            ++$i;
        }
        if ($start === $i) {
            return null;
        }

        return self::asciiLower(\substr($tagContent, $start, $i - $start));
    }

    /**
     * @param list<string> $allowed
     */
    private static function isTagAllowed(string $tagName, array $allowed): bool
    {
        $tagName = self::asciiLower($tagName);
        foreach ($allowed as $name) {
            if ($tagName === $name) {
                return true;
            }
        }

        return false;
    }

    private static function isTagWhitespace(string $ch): bool
    {
        return ' ' === $ch || "\t" === $ch || "\n" === $ch || "\r" === $ch || "\0" === $ch || "\x0B" === $ch;
    }

    private static function isTagChar(string $ch): bool
    {
        return ($ch >= 'a' && $ch <= 'z')
            || ($ch >= 'A' && $ch <= 'Z')
            || ($ch >= '0' && $ch <= '9');
    }

    private static function asciiLower(string $text): string
    {
        $out = '';
        $len = \strlen($text);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $text[$i];
            if ($ch >= 'A' && $ch <= 'Z') {
                $out .= \chr(\ord($ch) + 32);
            } else {
                $out .= $ch;
            }
        }

        return $out;
    }

    private static function findSubstring(string $haystack, string $needle, int $offset = 0): int|false
    {
        $pos = \strpos($haystack, $needle, $offset);

        return false === $pos ? false : $pos;
    }
}
