<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * metaphone() VM implementation (PHP 8.2-compatible subset; issue #2423).
 */
final class VmMetaphone
{
    private const SH = 'X';
    private const TH = '0';

    private static function upperAt(string $word, int $len, int $index): string
    {
        if ($index < 0 || $index >= $len) {
            return '';
        }
        // NestedJIT/AOT: `$word[$index]` offsets stay null / abort (#26794).
        $ch = \substr($word, $index, 1);
        if ('' === $ch) {
            return '';
        }
        $ord = \ord($ch);
        if ($ord >= 97 && $ord <= 122) {
            return \chr($ord - 32);
        }

        return $ch;
    }

    private static function isAlpha(string $c): bool
    {
        if ('' === $c) {
            return false;
        }
        $ord = \ord($c);

        return ($ord >= 65 && $ord <= 90) || ($ord >= 97 && $ord <= 122);
    }

    private static function toUpper(string $c): string
    {
        $o = \ord($c);
        if ($o >= 97 && $o <= 122) {
            return \chr($o - 32);
        }

        return $c;
    }

    private static function encodeChar(string $c): int
    {
        if (!self::isAlpha($c)) {
            return 0;
        }
        // NestedJIT/AOT: avoid class-const list indexing (#26794).
        // php-src ext/standard/string.c metaphone code table (A…Z).
        switch (\ord(self::toUpper($c))) {
            case 65: return 1;   // A
            case 66: return 16;  // B
            case 67: return 4;   // C
            case 68: return 16;  // D
            case 69: return 9;   // E
            case 70: return 2;   // F
            case 71: return 4;   // G
            case 72: return 16;  // H
            case 73: return 9;   // I
            case 74: return 2;   // J
            case 75: return 0;   // K
            case 76: return 2;   // L
            case 77: return 2;   // M
            case 78: return 2;   // N
            case 79: return 1;   // O
            case 80: return 4;   // P
            case 81: return 0;   // Q
            case 82: return 2;   // R
            case 83: return 4;   // S
            case 84: return 4;   // T
            case 85: return 1;   // U
            case 86: return 0;   // V
            case 87: return 0;   // W
            case 88: return 0;   // X
            case 89: return 8;   // Y
            case 90: return 0;   // Z
            default: return 0;
        }
    }

    private static function isVowel(string $c): bool
    {
        return 0 !== (self::encodeChar($c) & 1);
    }

    private static function makeSoft(string $c): bool
    {
        return 0 !== (self::encodeChar($c) & 8);
    }

    private static function affectH(string $c): bool
    {
        return 0 !== (self::encodeChar($c) & 4);
    }

    private static function noGhToF(string $c): bool
    {
        return 0 !== (self::encodeChar($c) & 16);
    }

    private static function isBreak(string $c): bool
    {
        return !self::isAlpha($c);
    }

    /** NestedJIT/AOT-safe phoneme concat (no by-ref string mutation) (#26794). */
    private static function appendPhoneme(string $out, string $c): string
    {
        return $out.$c;
    }

    /**
     * NestedJIT-safe index advance (#26815).
     *
     * Compound assign of a summed RHS (one plus skip) does not update the index under
     * NestedJIT/AOT — infinite phoneme loop → SIGKILL. Prefer ++$wIdx plus a separate delta.
     */
    private static function advanceIdx(int $wIdx, int $delta): int
    {
        $i = 0;
        while ($i < $delta) {
            ++$wIdx;
            ++$i;
        }

        return $wIdx;
    }

    public static function encode(string $word, int $maxPhonemes = 0): string
    {
        // php-src ext/standard/string.c — PHP_FUNCTION(metaphone) max_phonemes range (#29304).
        if ($maxPhonemes < 0) {
            throw new \ValueError('metaphone(): Argument #2 ($max_phonemes) must be greater than or equal to 0');
        }
        $len = \strlen($word);
        $out = '';
        $wIdx = 0;
        $traditional = true;

        while (!self::isAlpha(self::upperAt($word, $len, $wIdx))) {
            if ('' === self::upperAt($word, $len, $wIdx)) {
                return '';
            }
            ++$wIdx;
        }

        $curr = self::upperAt($word, $len, $wIdx);
        $next = self::upperAt($word, $len, $wIdx + 1);
        $afterNext = self::upperAt($word, $len, $wIdx + 2);

        switch ($curr) {
            case 'A':
                if ('E' === $next) {
                    $out = self::appendPhoneme($out, 'E');
                    $wIdx = self::advanceIdx($wIdx, 2);
                } else {
                    $out = self::appendPhoneme($out, 'A');
                    ++$wIdx;
                }
                break;
            case 'G':
            case 'K':
            case 'P':
                if ('N' === $next) {
                    $out = self::appendPhoneme($out, 'N');
                    $wIdx = self::advanceIdx($wIdx, 2);
                }
                break;
            case 'W':
                if ('R' === $next) {
                    $out = self::appendPhoneme($out, $next);
                    $wIdx = self::advanceIdx($wIdx, 2);
                } elseif ('H' === $next || self::isVowel($next)) {
                    $out = self::appendPhoneme($out, 'W');
                    $wIdx = self::advanceIdx($wIdx, 2);
                }
                break;
            case 'X':
                $out = self::appendPhoneme($out, 'S');
                ++$wIdx;
                break;
            case 'E':
            case 'I':
            case 'O':
            case 'U':
                $out = self::appendPhoneme($out, $curr);
                ++$wIdx;
                break;
        }

        while ('' !== self::upperAt($word, $len, $wIdx)) {
            $skip = 0;
            $curr = self::upperAt($word, $len, $wIdx);
            $next = self::upperAt($word, $len, $wIdx + 1);
            $prev = self::upperAt($word, $len, $wIdx - 1);
            $afterNext = self::upperAt($word, $len, $wIdx + 2);

            if (!self::isAlpha($curr)) {
                ++$wIdx;
                continue;
            }
            if ($curr === $prev && 'C' !== $curr) {
                ++$wIdx;
                continue;
            }

            switch ($curr) {
                case 'B':
                    if ('M' !== $prev) {
                        $out = self::appendPhoneme($out, 'B');
                    }
                    break;
                case 'C':
                    if (self::makeSoft($next)) {
                        if ('A' === $afterNext && 'I' === $next) {
                            $out = self::appendPhoneme($out, self::SH);
                        } elseif ('S' !== $prev) {
                            $out = self::appendPhoneme($out, 'S');
                        }
                    } elseif ('H' === $next) {
                        if (!$traditional && ('R' === $afterNext || 'S' === $prev)) {
                            $out = self::appendPhoneme($out, 'K');
                        } else {
                            $out = self::appendPhoneme($out, self::SH);
                        }
                        $skip = 1;
                    } else {
                        $out = self::appendPhoneme($out, 'K');
                    }
                    break;
                case 'D':
                    if ('G' === $next && self::makeSoft($afterNext)) {
                        $out = self::appendPhoneme($out, 'J');
                        $skip = 1;
                    } else {
                        $out = self::appendPhoneme($out, 'T');
                    }
                    break;
                case 'G':
                    if ('H' === $next) {
                        $lookBack3 = self::upperAt($word, $len, $wIdx - 3);
                        $lookBack4 = self::upperAt($word, $len, $wIdx - 4);
                        if (!self::noGhToF($lookBack3) && 'H' !== $lookBack4) {
                            $out = self::appendPhoneme($out, 'F');
                            $skip = 1;
                        }
                    } elseif ('N' === $next) {
                        $lookAhead3 = self::upperAt($word, $len, $wIdx + 3);
                        if (!self::isBreak($afterNext) && ('E' !== $afterNext || 'D' !== $lookAhead3)) {
                            $out = self::appendPhoneme($out, 'K');
                        }
                    } elseif (self::makeSoft($next) && 'G' !== $prev) {
                        $out = self::appendPhoneme($out, 'J');
                    } else {
                        $out = self::appendPhoneme($out, 'K');
                    }
                    break;
                case 'H':
                    if (self::isVowel($next) && !self::affectH($prev)) {
                        $out = self::appendPhoneme($out, 'H');
                    }
                    break;
                case 'K':
                    if ('C' !== $prev) {
                        $out = self::appendPhoneme($out, 'K');
                    }
                    break;
                case 'P':
                    if ('H' === $next) {
                        $out = self::appendPhoneme($out, 'F');
                    } else {
                        $out = self::appendPhoneme($out, 'P');
                    }
                    break;
                case 'Q':
                    $out = self::appendPhoneme($out, 'K');
                    break;
                case 'S':
                    if ('I' === $next && ('O' === $afterNext || 'A' === $afterNext)) {
                        $out = self::appendPhoneme($out, self::SH);
                    } elseif ('H' === $next) {
                        $out = self::appendPhoneme($out, self::SH);
                        $skip = 1;
                    } elseif (
                        !$traditional
                        && 'C' === $next
                        && 'H' === self::upperAt($word, $len, $wIdx + 2)
                        && 'W' === self::upperAt($word, $len, $wIdx + 3)
                    ) {
                        $out = self::appendPhoneme($out, self::SH);
                        $skip = 2;
                    } else {
                        $out = self::appendPhoneme($out, 'S');
                    }
                    break;
                case 'T':
                    if ('I' === $next && ('O' === $afterNext || 'A' === $afterNext)) {
                        $out = self::appendPhoneme($out, self::SH);
                    } elseif ('H' === $next) {
                        $out = self::appendPhoneme($out, self::TH);
                        $skip = 1;
                    } elseif (!('C' === $next && 'H' === $afterNext)) {
                        $out = self::appendPhoneme($out, 'T');
                    }
                    break;
                case 'V':
                    $out = self::appendPhoneme($out, 'F');
                    break;
                case 'W':
                    if (self::isVowel($next)) {
                        $out = self::appendPhoneme($out, 'W');
                    }
                    break;
                case 'X':
                    $out = self::appendPhoneme($out, 'K');
                    $out = self::appendPhoneme($out, 'S');
                    break;
                case 'Y':
                    if (self::isVowel($next)) {
                        $out = self::appendPhoneme($out, 'Y');
                    }
                    break;
                case 'Z':
                    $out = self::appendPhoneme($out, 'S');
                    break;
                case 'F':
                case 'J':
                case 'L':
                case 'M':
                case 'N':
                case 'R':
                    $out = self::appendPhoneme($out, $curr);
                    break;
            }

            ++$wIdx;
            if ($skip > 0) {
                $wIdx = self::advanceIdx($wIdx, $skip);
            }
        }

        if ($maxPhonemes > 0) {
            // NestedJIT/AOT: avoid strlen($out) mid-loop; truncate once (#26794).
            return \substr($out, 0, $maxPhonemes);
        }

        return $out;
    }
}

