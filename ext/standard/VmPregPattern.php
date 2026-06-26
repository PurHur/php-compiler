<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * PHP PCRE delimiter/pattern parsing — shared by VmPregNative and VmPregPure (#8935, #1492).
 *
 * php-src: ext/pcre/php_pcre.c — php_pcre_parse_pattern / compile failure messages
 */
final class VmPregPattern
{
    /**
     * Zend ext/pcre/php_pcre.c delimiter/compile failure text (issue #12083).
     */
    public static function patternWarningMessage(string $pattern): ?string
    {
        $len = \strlen($pattern);
        if (0 === $len) {
            return 'Empty regular expression';
        }
        $delimiter = $pattern[0];
        if (!self::isValidDelimiter($delimiter)) {
            return 'Delimiter must not be alphanumeric, backslash, or NUL';
        }
        if ($len < 2) {
            return \sprintf("No ending delimiter '%s' found", $delimiter);
        }

        $i = 1;
        while ($i < $len) {
            if ('\\' === $pattern[$i]) {
                if ($i + 1 < $len) {
                    $i += 2;
                    continue;
                }

                return \sprintf("No ending delimiter '%s' found", $delimiter);
            }
            if ($pattern[$i] === $delimiter) {
                break;
            }
            $i++;
        }
        if ($i >= $len) {
            return \sprintf("No ending delimiter '%s' found", $delimiter);
        }

        for ($j = $i + 1; $j < $len; $j++) {
            $mod = match ($pattern[$j]) {
                'i', 'm', 's', 'x', 'A', 'D', 'U', 'u' => true,
                default => null,
            };
            if (null === $mod) {
                return \sprintf("Unknown modifier '%s'", $pattern[$j]);
            }
        }

        return null;
    }

    /**
     * @return array{0: string, 1: int}|null [regex body, pcre2 option flags]
     */
    public static function parsePhpPattern(string $pattern): ?array
    {
        if (null !== self::patternWarningMessage($pattern)) {
            return null;
        }
        $len = \strlen($pattern);
        $delimiter = $pattern[0];

        $i = 1;
        while ($i < $len) {
            if ('\\' === $pattern[$i]) {
                $i += 2;
                continue;
            }
            if ($pattern[$i] === $delimiter) {
                break;
            }
            $i++;
        }

        $regex = \substr($pattern, 1, $i - 1);
        $opts = 0;
        for ($j = $i + 1; $j < $len; $j++) {
            $opts |= match ($pattern[$j]) {
                'i' => 0x00000008,
                'm' => 0x00000400,
                's' => 0x00000020,
                'x' => 0x00000080,
                'A' => 0x80000000,
                'D' => 0x00000010,
                'U' => 0x00040000,
                'u' => 0x00080000,
                default => 0,
            };
        }

        return [$regex, $opts];
    }

    public static function isValidDelimiter(string $c): bool
    {
        if ('' === $c || '\\' === $c) {
            return false;
        }
        $ord = \ord($c);

        return !(($ord >= 0x30 && $ord <= 0x39) || ($ord >= 0x41 && $ord <= 0x5A) || ($ord >= 0x61 && $ord <= 0x7A));
    }
}
