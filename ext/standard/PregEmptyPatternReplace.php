<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * preg_replace() empty-regex fast path (php-src ext/pcre/php_pcre.c, #11024).
 *
 * Shared by VM ({@see VmPregNative}) and JIT/AOT ({@see PregEmptyPatternReplaceJitHelper}).
 */
final class PregEmptyPatternReplace
{
    private const PCRE2_UTF = 0x00080000;

    /**
     * @return array{0: string, 1: int}|null null when $pattern is not an empty-regex PHP delimiter form
     */
    public static function parseEmptyPattern(string $pattern): ?array
    {
        $len = \strlen($pattern);
        if ($len < 2) {
            return null;
        }
        $delimiter = $pattern[0];
        if (!self::isValidDelimiter($delimiter)) {
            return null;
        }

        $i = 1;
        while ($i < $len) {
            if ('\\' === $pattern[$i]) {
                if ($i + 1 < $len) {
                    $i += 2;
                    continue;
                }

                return null;
            }
            if ($pattern[$i] === $delimiter) {
                break;
            }
            $i++;
        }
        if ($i >= $len) {
            return null;
        }

        $regex = \substr($pattern, 1, $i - 1);
        if ('' !== $regex) {
            return null;
        }

        $opts = 0;
        for ($j = $i + 1; $j < $len; $j++) {
            $mod = match ($pattern[$j]) {
                'i' => 0x00000008,
                'm' => 0x00000400,
                's' => 0x00000020,
                'x' => 0x00000080,
                'A' => 0x80000000,
                'D' => 0x00000010,
                'U' => 0x00040000,
                'u' => self::PCRE2_UTF,
                default => null,
            };
            if (null === $mod) {
                return null;
            }
            $opts |= $mod;
        }

        return [$regex, $opts];
    }

    public static function replace(
        string $replacement,
        string $subject,
        int $limit,
        int $opts,
        ?int &$count = null
    ): string {
        $utf8 = 0 !== ($opts & self::PCRE2_UTF);
        $max = $limit < 0 ? \PHP_INT_MAX : $limit;
        if (0 === $max) {
            $count = 0;

            return $subject;
        }

        /** @var list<array{0: string, 1: int}> $units */
        $units = [];
        if ($utf8) {
            $byteLen = \strlen($subject);
            for ($bytePos = 0; $bytePos < $byteLen;) {
                $width = self::utf8CharByteWidth($subject, $bytePos);
                $units[] = [\substr($subject, $bytePos, $width), $bytePos];
                $bytePos += $width;
            }
        } else {
            $byteLen = \strlen($subject);
            for ($i = 0; $i < $byteLen; ++$i) {
                $units[] = [$subject[$i], $i];
            }
        }

        $replacements = 0;
        $out = '';
        if ($replacements < $max) {
            $out .= $replacement;
            ++$replacements;
        }
        $unitCount = \count($units);
        for ($i = 0; $i < $unitCount; ++$i) {
            $out .= $units[$i][0];
            if ($replacements < $max) {
                $out .= $replacement;
                ++$replacements;
            }
        }

        $count = $replacements;

        return $out;
    }

    private static function isValidDelimiter(string $c): bool
    {
        if ('' === $c || '\\' === $c) {
            return false;
        }
        $ord = \ord($c);

        return !(($ord >= 0x30 && $ord <= 0x39) || ($ord >= 0x41 && $ord <= 0x5A) || ($ord >= 0x61 && $ord <= 0x7A));
    }

    private static function utf8CharByteWidth(string $string, int $offset): int
    {
        $len = \strlen($string);
        if ($offset >= $len) {
            return 0;
        }
        $byte = \ord($string[$offset]);
        if ($byte < 0x80) {
            return 1;
        }
        if (($byte & 0xE0) === 0xC0) {
            return 2;
        }
        if (($byte & 0xF0) === 0xE0) {
            return 3;
        }
        if (($byte & 0xF8) === 0xF0) {
            return 4;
        }

        return 1;
    }
}
