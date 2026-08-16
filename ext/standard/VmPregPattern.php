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
    /** PCRE2_UTF — PHP /u pattern modifier (ext/pcre/php_pcre.c). */
    public const PCRE2_UTF = 0x00080000;

    /**
     * Zend ext/pcre/php_pcre.c delimiter/compile failure text (issue #12083).
     *
     * Bracket-style delimiters (()[]{}<>) use a matching closer and nest
     * (php_pcre.c); unpaired open → "No ending matching delimiter".
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
        $end = self::findEndingDelimiterIndex($pattern);
        if (null === $end) {
            return self::missingEndingDelimiterMessage($delimiter);
        }

        for ($j = $end + 1; $j < $len; $j++) {
            $modifier = $pattern[$j];
            if ('e' === $modifier) {
                return 'The /e modifier is no longer supported, use preg_replace_callback instead';
            }
            if (!self::isKnownModifier($modifier)) {
                return \sprintf("Unknown modifier '%s'", $modifier);
            }
        }

        return null;
    }

    /**
     * Index of the ending delimiter, or null when missing (escaped bytes skipped).
     */
    public static function findEndingDelimiterIndex(string $pattern): ?int
    {
        $len = \strlen($pattern);
        if ($len < 2) {
            return null;
        }
        $open = $pattern[0];
        $close = self::matchingCloser($open);
        $nesting = ($close !== $open);
        $depth = 1;
        $i = 1;
        while ($i < $len) {
            if ('\\' === $pattern[$i]) {
                if ($i + 1 < $len) {
                    $i += 2;
                    continue;
                }

                return null;
            }
            $ch = $pattern[$i];
            if ($nesting) {
                if ($ch === $open) {
                    $depth++;
                } elseif ($ch === $close) {
                    $depth--;
                    if (0 === $depth) {
                        return $i;
                    }
                }
            } elseif ($ch === $close) {
                return $i;
            }
            $i++;
        }

        return null;
    }

    /** php-src: bracket delimiters close with the matching pair char. */
    public static function matchingCloser(string $open): string
    {
        return match ($open) {
            '(' => ')',
            '[' => ']',
            '{' => '}',
            '<' => '>',
            default => $open,
        };
    }

    private static function missingEndingDelimiterMessage(string $delimiter): string
    {
        $close = self::matchingCloser($delimiter);
        if ($close !== $delimiter) {
            return \sprintf("No ending matching delimiter '%s' found", $close);
        }

        return \sprintf("No ending delimiter '%s' found", $delimiter);
    }

    /** Nested JIT: match on $pattern[$j] after an e-check mis-lowers (#16075 tier-2). */
    private static function isKnownModifier(string $modifier): bool
    {
        return match ($modifier) {
            'i', 'm', 's', 'x', 'A', 'D', 'U', 'u', 'J' => true,
            default => false,
        };
    }

    /**
     * @return array{0: string, 1: int}|null [regex body, pcre2 option flags]
     */
    public static function parsePhpPattern(string $pattern): ?array
    {
        if (null !== self::patternWarningMessage($pattern)) {
            return null;
        }
        $end = self::findEndingDelimiterIndex($pattern);
        if (null === $end) {
            return null;
        }

        $regex = \substr($pattern, 1, $end - 1);
        $opts = 0;
        $len = \strlen($pattern);
        for ($j = $end + 1; $j < $len; $j++) {
            $opts |= self::modifierOptFlag($pattern[$j]);
        }

        return [$regex, $opts];
    }

    private static function modifierOptFlag(string $modifier): int
    {
        return match ($modifier) {
            'i' => 0x00000008,
            'm' => 0x00000400,
            's' => 0x00000020,
            'x' => 0x00000080,
            'A' => 0x80000000,
            'D' => 0x00000010,
            'U' => 0x00040000,
            'u' => self::PCRE2_UTF,
            'J' => 0x00100000,
            default => 0,
        };
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
