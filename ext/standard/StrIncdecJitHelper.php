<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * str_increment()/str_decrement() and zend increment_string() for JIT/AOT
 * (#14850, #27345, #32435).
 *
 * Semantics match {@see VmString::strIncrement()} / {@see VmString::strDecrement()}
 * and {@see VmString::incrementStringOperator()} (php-src ext/standard/string.c /
 * Zend/zend_operators.c). Inlined for NestedJIT — no VmString::* calls
 * (#27345 / peer #23204 StrRepeat).
 *
 * NestedJIT notes (peer StrReplace #27079):
 * - No VmString::* calls (unbound stub under thin AOT)
 * - Never re-index a reassigned string — NestedJIT sticky-reads the old byte
 * - Separate no-wrap / wrap / full-wrap paths
 * - Leading-zero strip: branch on original `$string[0]==='1'` (not `!$skip` on
 *   computed bumped — that predicate segfaulted under NestedJIT for "10")
 * - Next/prev via match literals (not \chr)
 */
final class StrIncdecJitHelper
{
    public static function incrementArgv(string $string): string
    {
        if ('' === $string) {
            throw new \ValueError('str_increment(): Argument #1 ($string) must not be empty');
        }
        if (!self::onlyAsciiAlphanumeric($string)) {
            throw new \ValueError('str_increment(): Argument #1 ($string) must be composed only of alphanumeric ASCII characters');
        }

        $len = self::byteLen($string);
        $last = $len - 1;
        $lastChar = $string[$last];

        if ('9' !== $lastChar && 'Z' !== $lastChar && 'z' !== $lastChar) {
            return self::bumpAt($string, $len, $last, true);
        }

        $wrapFrom = $last;
        $i = $last - 1;
        while ($i >= 0) {
            $c = $string[$i];
            if ('9' === $c || 'Z' === $c || 'z' === $c) {
                $wrapFrom = $i;
                $i = $i - 1;
            } else {
                break;
            }
        }

        if (0 === $wrapFrom) {
            return self::lengthenInc($string, $len);
        }

        return self::bumpThenWrapInc($string, $len, $wrapFrom);
    }

    public static function decrementArgv(string $string): string
    {
        if ('' === $string) {
            throw new \ValueError('str_decrement(): Argument #1 ($string) must not be empty');
        }
        if (!self::onlyAsciiAlphanumeric($string)) {
            throw new \ValueError('str_decrement(): Argument #1 ($string) must be composed only of alphanumeric ASCII characters');
        }
        if ('0' === $string[0]) {
            throw new \ValueError('str_decrement(): Argument #1 ($string) "'.$string.'" is out of decrement range');
        }

        $len = self::byteLen($string);
        $last = $len - 1;
        $lastChar = $string[$last];

        if ('0' !== $lastChar && 'A' !== $lastChar && 'a' !== $lastChar) {
            return self::bumpAt($string, $len, $last, false);
        }

        $wrapFrom = $last;
        $i = $last - 1;
        while ($i >= 0) {
            $c = $string[$i];
            if ('0' === $c || 'A' === $c || 'a' === $c) {
                $wrapFrom = $i;
                $i = $i - 1;
            } else {
                break;
            }
        }

        if (0 === $wrapFrom) {
            if (1 === $len) {
                throw new \ValueError('str_decrement(): Argument #1 ($string) "'.$string.'" is out of decrement range');
            }

            return self::underflowDec($string, $len);
        }

        return self::bumpThenWrapDec($string, $len, $wrapFrom);
    }

    /**
     * zend_operators.c increment_string() — empty → '1'; non-alnum stops the carry
     * without ValueError (unlike {@see incrementArgv}). (#32435)
     */
    public static function operatorIncrement(string $string): string
    {
        if ('' === $string) {
            return '1';
        }
        if (self::onlyAsciiAlphanumeric($string)) {
            return self::incrementArgv($string);
        }

        return self::incrementStringMixed($string);
    }

    /**
     * Classify a string for ++/-- (zend is_numeric then increment_string).
     * 0 = non-numeric, 1 = integral numeric, 2 = float numeric. (#32435)
     */
    public static function numericIncDecKind(string $s): int
    {
        $i = 0;
        $len = self::byteLen($s);
        if (0 === $len) {
            return 0;
        }
        while ($i < $len && (' ' === $s[$i] || "\t" === $s[$i])) {
            $i = $i + 1;
        }
        if ($i >= $len) {
            return 0;
        }
        if ('+' === $s[$i] || '-' === $s[$i]) {
            $i = $i + 1;
        }
        if ($i >= $len) {
            return 0;
        }
        $sawDigit = 0;
        $sawDot = 0;
        $sawExp = 0;
        while ($i < $len) {
            $c = $s[$i];
            $o = \ord($c);
            if ($o >= 48 && $o <= 57) {
                $sawDigit = 1;
                $i = $i + 1;
            } elseif ('.' === $c && 0 === $sawDot && 0 === $sawExp) {
                $sawDot = 1;
                $i = $i + 1;
            } elseif (('e' === $c || 'E' === $c) && 0 === $sawExp && 1 === $sawDigit) {
                $sawExp = 1;
                $i = $i + 1;
                if ($i < $len && ('+' === $s[$i] || '-' === $s[$i])) {
                    $i = $i + 1;
                }
            } elseif (' ' === $c || "\t" === $s[$i]) {
                break;
            } else {
                return 0;
            }
        }
        while ($i < $len) {
            if (' ' !== $s[$i] && "\t" !== $s[$i]) {
                return 0;
            }
            $i = $i + 1;
        }
        if (0 === $sawDigit) {
            return 0;
        }
        if (1 === $sawDot || 1 === $sawExp) {
            return 2;
        }

        return 1;
    }

    private static function incrementStringMixed(string $string): string
    {
        $len = self::byteLen($string);
        $last = $len - 1;
        $lastChar = $string[$last];
        if (!self::isAsciiAlnumChar($lastChar)) {
            return $string;
        }
        if ('9' !== $lastChar && 'Z' !== $lastChar && 'z' !== $lastChar) {
            return self::bumpAt($string, $len, $last, true);
        }
        $wrapFrom = $last;
        $i = $last - 1;
        while ($i >= 0) {
            $c = $string[$i];
            if ('9' === $c || 'Z' === $c || 'z' === $c) {
                $wrapFrom = $i;
                $i = $i - 1;
            } else {
                break;
            }
        }
        if ($i < 0) {
            return self::lengthenInc($string, $len);
        }
        if (self::isAsciiAlnumChar($string[$i])) {
            return self::bumpThenWrapInc($string, $len, $wrapFrom);
        }

        return self::wrapSuffixOnly($string, $len, $wrapFrom);
    }

    private static function wrapSuffixOnly(string $string, int $len, int $wrapFrom): string
    {
        $out = '';
        $j = 0;
        while ($j < $len) {
            if ($j >= $wrapFrom) {
                $c = $string[$j];
                if ('9' === $c) {
                    $out = self::concat($out, '0');
                } elseif ('Z' === $c) {
                    $out = self::concat($out, 'A');
                } else {
                    $out = self::concat($out, 'a');
                }
            } else {
                $out = self::concat($out, $string[$j]);
            }
            $j = $j + 1;
        }

        return $out;
    }

    private static function isAsciiAlnumChar(string $c): bool
    {
        $o = \ord($c);

        return ($o >= 48 && $o <= 57) || ($o >= 65 && $o <= 90) || ($o >= 97 && $o <= 122);
    }

    private static function bumpAt(string $string, int $len, int $at, bool $increment): string
    {
        $out = '';
        $j = 0;
        while ($j < $len) {
            if ($j === $at) {
                if ($increment) {
                    $out = self::concat($out, self::nextChar($string[$j]));
                } else {
                    $out = self::concat($out, self::prevChar($string[$j]));
                }
            } else {
                $out = self::concat($out, $string[$j]);
            }
            $j = $j + 1;
        }

        return $out;
    }

    private static function lengthenInc(string $string, int $len): string
    {
        $first = $string[0];
        $prefix = '1';
        if ('Z' === $first) {
            $prefix = 'A';
        } elseif ('z' === $first) {
            $prefix = 'a';
        }
        $body = '';
        $j = 0;
        while ($j < $len) {
            $c = $string[$j];
            if ('9' === $c) {
                $body = self::concat($body, '0');
            } elseif ('Z' === $c) {
                $body = self::concat($body, 'A');
            } else {
                $body = self::concat($body, 'a');
            }
            $j = $j + 1;
        }

        return self::concat($prefix, $body);
    }

    private static function bumpThenWrapInc(string $string, int $len, int $wrapFrom): string
    {
        $out = '';
        $bumpAt = $wrapFrom - 1;
        $j = 0;
        while ($j < $len) {
            if ($j === $bumpAt) {
                $out = self::concat($out, self::nextChar($string[$j]));
            } elseif ($j >= $wrapFrom) {
                $c = $string[$j];
                if ('9' === $c) {
                    $out = self::concat($out, '0');
                } elseif ('Z' === $c) {
                    $out = self::concat($out, 'A');
                } else {
                    $out = self::concat($out, 'a');
                }
            } else {
                $out = self::concat($out, $string[$j]);
            }
            $j = $j + 1;
        }

        return $out;
    }

    private static function underflowDec(string $string, int $len): string
    {
        $body = '';
        $j = 1;
        while ($j < $len) {
            $c = $string[$j];
            if ('0' === $c) {
                $body = self::concat($body, '9');
            } elseif ('A' === $c) {
                $body = self::concat($body, 'Z');
            } else {
                $body = self::concat($body, 'z');
            }
            $j = $j + 1;
        }

        return $body;
    }

    private static function bumpThenWrapDec(string $string, int $len, int $wrapFrom): string
    {
        $bumpAt = $wrapFrom - 1;
        // Leading zero after borrow: prev('1')==='0'. Reuse underflowDec (skip first,
        // wrap rest) — a local skip predicate segfaulted NestedJIT for "10" (#27345).
        if (0 === $bumpAt && $len > 1 && '1' === $string[0]) {
            return self::underflowDec($string, $len);
        }

        $out = '';
        $j = 0;
        while ($j < $len) {
            if ($j === $bumpAt) {
                $out = self::concat($out, self::prevChar($string[$j]));
            } elseif ($j >= $wrapFrom) {
                $c = $string[$j];
                if ('0' === $c) {
                    $out = self::concat($out, '9');
                } elseif ('A' === $c) {
                    $out = self::concat($out, 'Z');
                } else {
                    $out = self::concat($out, 'z');
                }
            } else {
                $out = self::concat($out, $string[$j]);
            }
            $j = $j + 1;
        }

        return $out;
    }

    private static function onlyAsciiAlphanumeric(string $string): bool
    {
        $i = 0;
        while (isset($string[$i])) {
            $o = \ord($string[$i]);
            if (!(($o >= 48 && $o <= 57) || ($o >= 65 && $o <= 90) || ($o >= 97 && $o <= 122))) {
                return false;
            }
            ++$i;
        }

        return true;
    }

    private static function nextChar(string $c): string
    {
        return match ($c) {
            '0' => '1', '1' => '2', '2' => '3', '3' => '4', '4' => '5',
            '5' => '6', '6' => '7', '7' => '8', '8' => '9',
            'A' => 'B', 'B' => 'C', 'C' => 'D', 'D' => 'E', 'E' => 'F',
            'F' => 'G', 'G' => 'H', 'H' => 'I', 'I' => 'J', 'J' => 'K',
            'K' => 'L', 'L' => 'M', 'M' => 'N', 'N' => 'O', 'O' => 'P',
            'P' => 'Q', 'Q' => 'R', 'R' => 'S', 'S' => 'T', 'T' => 'U',
            'U' => 'V', 'V' => 'W', 'W' => 'X', 'X' => 'Y', 'Y' => 'Z',
            'a' => 'b', 'b' => 'c', 'c' => 'd', 'd' => 'e', 'e' => 'f',
            'f' => 'g', 'g' => 'h', 'h' => 'i', 'i' => 'j', 'j' => 'k',
            'k' => 'l', 'l' => 'm', 'm' => 'n', 'n' => 'o', 'o' => 'p',
            'p' => 'q', 'q' => 'r', 'r' => 's', 's' => 't', 't' => 'u',
            'u' => 'v', 'v' => 'w', 'w' => 'x', 'x' => 'y', 'y' => 'z',
            default => $c,
        };
    }

    private static function prevChar(string $c): string
    {
        return match ($c) {
            '1' => '0', '2' => '1', '3' => '2', '4' => '3', '5' => '4',
            '6' => '5', '7' => '6', '8' => '7', '9' => '8',
            'B' => 'A', 'C' => 'B', 'D' => 'C', 'E' => 'D', 'F' => 'E',
            'G' => 'F', 'H' => 'G', 'I' => 'H', 'J' => 'I', 'K' => 'J',
            'L' => 'K', 'M' => 'L', 'N' => 'M', 'O' => 'N', 'P' => 'O',
            'Q' => 'P', 'R' => 'Q', 'S' => 'R', 'T' => 'S', 'U' => 'T',
            'V' => 'U', 'W' => 'V', 'X' => 'W', 'Y' => 'X', 'Z' => 'Y',
            'b' => 'a', 'c' => 'b', 'd' => 'c', 'e' => 'd', 'f' => 'e',
            'g' => 'f', 'h' => 'g', 'i' => 'h', 'j' => 'i', 'k' => 'j',
            'l' => 'k', 'm' => 'l', 'n' => 'm', 'o' => 'n', 'p' => 'o',
            'q' => 'p', 'r' => 'q', 's' => 'r', 't' => 's', 'u' => 't',
            'v' => 'u', 'w' => 'v', 'x' => 'w', 'y' => 'x', 'z' => 'y',
            default => $c,
        };
    }

    private static function byteLen(string $s): int
    {
        $n = 0;
        while (true) {
            if (!isset($s[$n])) {
                return $n;
            }
            ++$n;
        }
    }

    private static function concat(string $left, string $right): string
    {
        $out = '';
        $out .= $left;
        $out .= $right;

        return $out;
    }
}
