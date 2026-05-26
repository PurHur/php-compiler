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

    public static function encode(string $word, int $maxPhonemes = 0): string
    {
        if ($maxPhonemes < 0) {
            throw new \LogicException('metaphone() max phonemes must be >= 0');
        }
        $len = \strlen($word);
        $out = '';
        $wIdx = 0;
        $traditional = true;
        $upperAt = static function (int $index) use ($word, $len): string {
            if ($index < 0 || $index >= $len) {
                return '';
            }
            $ord = \ord($word[$index]);
            if ($ord >= 97 && $ord <= 122) {
                return \chr($ord - 32);
            }

            return $word[$index];
        };

        $curr = static function () use ($upperAt, &$wIdx): string {
            return $upperAt($wIdx);
        };
        $next = static function () use ($upperAt, &$wIdx): string {
            return $upperAt($wIdx + 1);
        };
        $prev = static function () use ($upperAt, &$wIdx): string {
            return $upperAt($wIdx - 1);
        };
        $afterNext = static function () use ($upperAt, &$wIdx): string {
            return $upperAt($wIdx + 2);
        };
        $lookBack = static function (int $n) use ($upperAt, &$wIdx): string {
            return $upperAt($wIdx - $n);
        };
        $lookAhead = static function (int $n) use ($upperAt, &$wIdx): string {
            return $upperAt($wIdx + $n);
        };
        $phonize = static function (string $c) use (&$out, $maxPhonemes): void {
            if (0 !== $maxPhonemes && \strlen($out) >= $maxPhonemes) {
                return;
            }
            $out .= $c;
        };
        $isAlpha = static function (string $c): bool {
            if ('' === $c) {
                return false;
            }
            $ord = \ord($c);

            return ($ord >= 65 && $ord <= 90) || ($ord >= 97 && $ord <= 122);
        };
        $toUpper = static function (string $c): string {
            $o = \ord($c);
            if ($o >= 97 && $o <= 122) {
                return \chr($o - 32);
            }

            return $c;
        };
        $encode = static function (string $c) use ($isAlpha, $toUpper): int {
            if (!$isAlpha($c)) {
                return 0;
            }
            $o = \ord($toUpper($c));

            return self::CODES[$o - 65];
        };
        $isVowel = static function (string $c) use ($encode): bool {
            return 0 !== ($encode($c) & 1);
        };
        $makeSoft = static function (string $c) use ($encode): bool {
            return 0 !== ($encode($c) & 8);
        };
        $affectH = static function (string $c) use ($encode): bool {
            return 0 !== ($encode($c) & 4);
        };
        $noGhToF = static function (string $c) use ($encode): bool {
            return 0 !== ($encode($c) & 16);
        };
        $isBreak = static function (string $c) use ($isAlpha): bool {
            return !$isAlpha($c);
        };

        while (!$isAlpha($curr())) {
            if ('' === $curr()) {
                return '';
            }
            ++$wIdx;
        }

        switch ($curr()) {
            case 'A':
                if ('E' === $next()) {
                    $phonize('E');
                    $wIdx += 2;
                } else {
                    $phonize('A');
                    ++$wIdx;
                }
                break;
            case 'G':
            case 'K':
            case 'P':
                if ('N' === $next()) {
                    $phonize('N');
                    $wIdx += 2;
                }
                break;
            case 'W':
                if ('R' === $next()) {
                    $phonize($next());
                    $wIdx += 2;
                } elseif ('H' === $next() || $isVowel($next())) {
                    $phonize('W');
                    $wIdx += 2;
                }
                break;
            case 'X':
                $phonize('S');
                ++$wIdx;
                break;
            case 'E':
            case 'I':
            case 'O':
            case 'U':
                $phonize($curr());
                ++$wIdx;
                break;
        }

        while ('' !== $curr() && (0 === $maxPhonemes || \strlen($out) < $maxPhonemes)) {
            $skip = 0;
            if (!$isAlpha($curr())) {
                ++$wIdx;
                continue;
            }
            if ($curr() === $prev() && 'C' !== $curr()) {
                ++$wIdx;
                continue;
            }

            switch ($curr()) {
                case 'B':
                    if ('M' !== $prev()) {
                        $phonize('B');
                    }
                    break;
                case 'C':
                    if ($makeSoft($next())) {
                        if ('A' === $afterNext() && 'I' === $next()) {
                            $phonize(self::SH);
                        } elseif ('S' !== $prev()) {
                            $phonize('S');
                        }
                    } elseif ('H' === $next()) {
                        if (!$traditional && ('R' === $afterNext() || 'S' === $prev())) {
                            $phonize('K');
                        } else {
                            $phonize(self::SH);
                        }
                        $skip = 1;
                    } else {
                        $phonize('K');
                    }
                    break;
                case 'D':
                    if ('G' === $next() && $makeSoft($afterNext())) {
                        $phonize('J');
                        $skip = 1;
                    } else {
                        $phonize('T');
                    }
                    break;
                case 'G':
                    if ('H' === $next()) {
                        if (!$noGhToF($lookBack(3)) && 'H' !== $lookBack(4)) {
                            $phonize('F');
                            $skip = 1;
                        }
                    } elseif ('N' === $next()) {
                        if (!$isBreak($afterNext()) && ('E' !== $afterNext() || 'D' !== $lookAhead(3))) {
                            $phonize('K');
                        }
                    } elseif ($makeSoft($next()) && 'G' !== $prev()) {
                        $phonize('J');
                    } else {
                        $phonize('K');
                    }
                    break;
                case 'H':
                    if ($isVowel($next()) && !$affectH($prev())) {
                        $phonize('H');
                    }
                    break;
                case 'K':
                    if ('C' !== $prev()) {
                        $phonize('K');
                    }
                    break;
                case 'P':
                    if ('H' === $next()) {
                        $phonize('F');
                    } else {
                        $phonize('P');
                    }
                    break;
                case 'Q':
                    $phonize('K');
                    break;
                case 'S':
                    if ('I' === $next() && ('O' === $afterNext() || 'A' === $afterNext())) {
                        $phonize(self::SH);
                    } elseif ('H' === $next()) {
                        $phonize(self::SH);
                        $skip = 1;
                    } elseif (
                        !$traditional
                        && 'C' === $next()
                        && 'H' === $lookAhead(2)
                        && 'W' === $lookAhead(3)
                    ) {
                        $phonize(self::SH);
                        $skip = 2;
                    } else {
                        $phonize('S');
                    }
                    break;
                case 'T':
                    if ('I' === $next() && ('O' === $afterNext() || 'A' === $afterNext())) {
                        $phonize(self::SH);
                    } elseif ('H' === $next()) {
                        $phonize(self::TH);
                        $skip = 1;
                    } elseif (!('C' === $next() && 'H' === $afterNext())) {
                        $phonize('T');
                    }
                    break;
                case 'V':
                    $phonize('F');
                    break;
                case 'W':
                    if ($isVowel($next())) {
                        $phonize('W');
                    }
                    break;
                case 'X':
                    $phonize('K');
                    $phonize('S');
                    break;
                case 'Y':
                    if ($isVowel($next())) {
                        $phonize('Y');
                    }
                    break;
                case 'Z':
                    $phonize('S');
                    break;
                case 'F':
                case 'J':
                case 'L':
                case 'M':
                case 'N':
                case 'R':
                    $phonize($curr());
                    break;
            }

            $wIdx += 1 + $skip;
        }

        return $out;
    }
}
