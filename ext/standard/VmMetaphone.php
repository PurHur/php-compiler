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

    /** @var list<int> */
    private const CODES = [
        1, 16, 4, 16, 9, 2, 4, 16, 9, 2, 0, 2, 2, 2, 1, 4, 0, 2, 4, 4, 1, 0, 0, 0, 8, 0,
    ];

    private static function upperAt(string $word, int $len, int $index): string
    {
        if ($index < 0 || $index >= $len) {
            return '';
        }
        $ord = \ord($word[$index]);
        if ($ord >= 97 && $ord <= 122) {
            return \chr($ord - 32);
        }

        return $word[$index];
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
        $o = \ord(self::toUpper($c));

        return self::CODES[$o - 65];
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

    private static function phonize(string &$out, string $c, int $maxPhonemes): void
    {
        if (0 !== $maxPhonemes && \strlen($out) >= $maxPhonemes) {
            return;
        }
        $out .= $c;
    }

    public static function encode(string $word, int $maxPhonemes = 0): string
    {
        if ($maxPhonemes < 0) {
            throw new \LogicException('metaphone() max phonemes must be >= 0');
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
                    self::phonize($out, 'E', $maxPhonemes);
                    $wIdx += 2;
                } else {
                    self::phonize($out, 'A', $maxPhonemes);
                    ++$wIdx;
                }
                break;
            case 'G':
            case 'K':
            case 'P':
                if ('N' === $next) {
                    self::phonize($out, 'N', $maxPhonemes);
                    $wIdx += 2;
                }
                break;
            case 'W':
                if ('R' === $next) {
                    self::phonize($out, $next, $maxPhonemes);
                    $wIdx += 2;
                } elseif ('H' === $next || self::isVowel($next)) {
                    self::phonize($out, 'W', $maxPhonemes);
                    $wIdx += 2;
                }
                break;
            case 'X':
                self::phonize($out, 'S', $maxPhonemes);
                ++$wIdx;
                break;
            case 'E':
            case 'I':
            case 'O':
            case 'U':
                self::phonize($out, $curr, $maxPhonemes);
                ++$wIdx;
                break;
        }

        while ('' !== self::upperAt($word, $len, $wIdx) && (0 === $maxPhonemes || \strlen($out) < $maxPhonemes)) {
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
                        self::phonize($out, 'B', $maxPhonemes);
                    }
                    break;
                case 'C':
                    if (self::makeSoft($next)) {
                        if ('A' === $afterNext && 'I' === $next) {
                            self::phonize($out, self::SH, $maxPhonemes);
                        } elseif ('S' !== $prev) {
                            self::phonize($out, 'S', $maxPhonemes);
                        }
                    } elseif ('H' === $next) {
                        if (!$traditional && ('R' === $afterNext || 'S' === $prev)) {
                            self::phonize($out, 'K', $maxPhonemes);
                        } else {
                            self::phonize($out, self::SH, $maxPhonemes);
                        }
                        $skip = 1;
                    } else {
                        self::phonize($out, 'K', $maxPhonemes);
                    }
                    break;
                case 'D':
                    if ('G' === $next && self::makeSoft($afterNext)) {
                        self::phonize($out, 'J', $maxPhonemes);
                        $skip = 1;
                    } else {
                        self::phonize($out, 'T', $maxPhonemes);
                    }
                    break;
                case 'G':
                    if ('H' === $next) {
                        $lookBack3 = self::upperAt($word, $len, $wIdx - 3);
                        $lookBack4 = self::upperAt($word, $len, $wIdx - 4);
                        if (!self::noGhToF($lookBack3) && 'H' !== $lookBack4) {
                            self::phonize($out, 'F', $maxPhonemes);
                            $skip = 1;
                        }
                    } elseif ('N' === $next) {
                        $lookAhead3 = self::upperAt($word, $len, $wIdx + 3);
                        if (!self::isBreak($afterNext) && ('E' !== $afterNext || 'D' !== $lookAhead3)) {
                            self::phonize($out, 'K', $maxPhonemes);
                        }
                    } elseif (self::makeSoft($next) && 'G' !== $prev) {
                        self::phonize($out, 'J', $maxPhonemes);
                    } else {
                        self::phonize($out, 'K', $maxPhonemes);
                    }
                    break;
                case 'H':
                    if (self::isVowel($next) && !self::affectH($prev)) {
                        self::phonize($out, 'H', $maxPhonemes);
                    }
                    break;
                case 'K':
                    if ('C' !== $prev) {
                        self::phonize($out, 'K', $maxPhonemes);
                    }
                    break;
                case 'P':
                    if ('H' === $next) {
                        self::phonize($out, 'F', $maxPhonemes);
                    } else {
                        self::phonize($out, 'P', $maxPhonemes);
                    }
                    break;
                case 'Q':
                    self::phonize($out, 'K', $maxPhonemes);
                    break;
                case 'S':
                    if ('I' === $next && ('O' === $afterNext || 'A' === $afterNext)) {
                        self::phonize($out, self::SH, $maxPhonemes);
                    } elseif ('H' === $next) {
                        self::phonize($out, self::SH, $maxPhonemes);
                        $skip = 1;
                    } elseif (
                        !$traditional
                        && 'C' === $next
                        && 'H' === self::upperAt($word, $len, $wIdx + 2)
                        && 'W' === self::upperAt($word, $len, $wIdx + 3)
                    ) {
                        self::phonize($out, self::SH, $maxPhonemes);
                        $skip = 2;
                    } else {
                        self::phonize($out, 'S', $maxPhonemes);
                    }
                    break;
                case 'T':
                    if ('I' === $next && ('O' === $afterNext || 'A' === $afterNext)) {
                        self::phonize($out, self::SH, $maxPhonemes);
                    } elseif ('H' === $next) {
                        self::phonize($out, self::TH, $maxPhonemes);
                        $skip = 1;
                    } elseif (!('C' === $next && 'H' === $afterNext)) {
                        self::phonize($out, 'T', $maxPhonemes);
                    }
                    break;
                case 'V':
                    self::phonize($out, 'F', $maxPhonemes);
                    break;
                case 'W':
                    if (self::isVowel($next)) {
                        self::phonize($out, 'W', $maxPhonemes);
                    }
                    break;
                case 'X':
                    self::phonize($out, 'K', $maxPhonemes);
                    self::phonize($out, 'S', $maxPhonemes);
                    break;
                case 'Y':
                    if (self::isVowel($next)) {
                        self::phonize($out, 'Y', $maxPhonemes);
                    }
                    break;
                case 'Z':
                    self::phonize($out, 'S', $maxPhonemes);
                    break;
                case 'F':
                case 'J':
                case 'L':
                case 'M':
                case 'N':
                case 'R':
                    self::phonize($out, $curr, $maxPhonemes);
                    break;
            }

            $wIdx += 1 + $skip;
        }

        return $out;
    }
}
