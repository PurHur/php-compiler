<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\Frame;
use PHPCompiler\VM\HashTable;

/**
 * PECL stats_rand_* — L'Ecuyer combined MRG (randlib com.c / ignlgi) (#29589, #29622).
 *
 * php-src: pecl-math-stats com.c + randlib.c (setall/getsd/ignlgi/ranf/ignuin/
 * genbet/sexpo/snorm/sgamma/phrtsd). gen_normal uses RANLIB snorm() (#29622).
 */
final class VmStatsRand
{
    public const OP_GEN_BETA = 1;
    public const OP_GEN_EXPONENTIAL = 2;
    public const OP_GEN_GAMMA = 3;

    private const NUMG = 32;

    private static bool $commonInit = false;
    private static bool $seedSet = false;
    private static int $currentG = 1;

    private static int $m1 = 2147483563;
    private static int $m2 = 2147483399;
    private static int $a1 = 40014;
    private static int $a2 = 40692;
    private static int $a1w = 1033780774;
    private static int $a2w = 1494757890;
    private static int $a1vw = 2082007225;
    private static int $a2vw = 784306273;

    /** @var list<int> */
    private static array $cg1 = [];
    /** @var list<int> */
    private static array $cg2 = [];
    /** @var list<int> */
    private static array $ig1 = [];
    /** @var list<int> */
    private static array $ig2 = [];
    /** @var list<int> */
    private static array $lg1 = [];
    /** @var list<int> */
    private static array $lg2 = [];
    /** @var list<int> */
    private static array $qanti = [];

    private static bool $hasSpare = false;
    private static float $spare = 0.0;

    public static function setall(int $iseed1, int $iseed2): bool
    {
        self::$seedSet = true;
        if (!self::$commonInit) {
            self::inrgcm();
        }
        $ocgn = self::$currentG;
        self::$ig1[0] = $iseed1;
        self::$ig2[0] = $iseed2;
        self::initgn(-1);
        for ($g = 2; $g <= self::NUMG; ++$g) {
            self::$ig1[$g - 1] = self::mltmod(self::$a1vw, self::$ig1[$g - 2], self::$m1);
            self::$ig2[$g - 1] = self::mltmod(self::$a2vw, self::$ig2[$g - 2], self::$m2);
            self::$currentG = $g;
            self::initgn(-1);
        }
        self::$currentG = $ocgn;
        self::$hasSpare = false;
        VmStatsRandlib::resetCaches();

        return true;
    }

    /** @return array{0: int, 1: int} */
    public static function getsd(): array
    {
        if (!self::$commonInit) {
            self::inrgcm();
            if (!self::$seedSet) {
                self::setall(1234567890, 123456789);
            }
        }
        $g = self::$currentG;

        return [self::$cg1[$g - 1], self::$cg2[$g - 1]];
    }

    public static function ignlgi(): int
    {
        if (!self::$commonInit) {
            self::inrgcm();
        }
        if (!self::$seedSet) {
            self::setall(1234567890, 123456789);
        }
        $g = self::$currentG;
        $s1 = self::$cg1[$g - 1];
        $s2 = self::$cg2[$g - 1];
        $k = intdiv($s1, 53668);
        $s1 = self::$a1 * ($s1 - $k * 53668) - $k * 12211;
        if ($s1 < 0) {
            $s1 += self::$m1;
        }
        $k = intdiv($s2, 52774);
        $s2 = self::$a2 * ($s2 - $k * 52774) - $k * 3791;
        if ($s2 < 0) {
            $s2 += self::$m2;
        }
        self::$cg1[$g - 1] = $s1;
        self::$cg2[$g - 1] = $s2;
        $z = $s1 - $s2;
        if ($z < 1) {
            $z += self::$m1 - 1;
        }
        if (0 !== self::$qanti[$g - 1]) {
            $z = self::$m1 - $z;
        }

        return $z;
    }

    public static function ranf(): float
    {
        return self::ignlgi() * 4.656613057E-10;
    }

    /** @return int|false */
    public static function genIuniform(int $low, int $high, ?Frame $frame)
    {
        if ($high - $low > 2147483561) {
            VmStats::triggerWarning($frame, \sprintf(
                'high - low too large. low : %16ld high %16ld',
                $low,
                $high
            ));

            return false;
        }
        if ($low > $high) {
            VmStats::triggerWarning($frame, \sprintf(
                'low greater than high. low : %16ld high %16ld',
                $low,
                $high
            ));

            return false;
        }
        if ($low === $high) {
            return $low;
        }
        $range = $high - $low;
        $ranp1 = $range + 1;
        $maxnum = 2147483561;
        $maxnow = intdiv($maxnum, $ranp1) * $ranp1;
        do {
            $ign = self::ignlgi() - 1;
        } while ($ign > $maxnow);

        return $low + ($ign % $ranp1);
    }

    /** @return float|false */
    public static function genNormal(float $av, float $sd, ?Frame $frame)
    {
        if ($sd < 0.0) {
            VmStats::triggerWarning($frame, \sprintf('sd < 0.0 . sd : %16.6E', $sd));

            return false;
        }

        return $av + $sd * VmStatsRandlib::snorm();
    }

    /** @return float|false */
    public static function genBeta(float $a, float $b, ?Frame $frame)
    {
        if ($a < 1.0E-37 || $b < 1.0E-37) {
            VmStats::triggerWarning($frame, \sprintf(
                "'a' or 'b' lower than 1.0E-37. 'a' value : %16.6E 'b' value : %16.6E",
                $a,
                $b
            ));

            return false;
        }

        return VmStatsRandlib::genbet($a, $b);
    }

    /** @return float|false */
    public static function genExponential(float $av, ?Frame $frame)
    {
        if ($av < 0.0) {
            VmStats::triggerWarning($frame, 'av < 0.0');

            return false;
        }

        return VmStatsRandlib::sexpo() * $av;
    }

    /** @return float|false */
    public static function genGamma(float $a, float $r, ?Frame $frame)
    {
        if (!($a > 0.0 && $r > 0.0)) {
            VmStats::triggerWarning($frame, \sprintf(
                'A or R nonpositive. A value : %16.6E , R value : %16.6E',
                $a,
                $r
            ));

            return false;
        }

        return VmStatsRandlib::sgamma($r) / $a;
    }

    public static function getsdHashTable(): HashTable
    {
        $seeds = self::getsd();
        $ht = new HashTable();
        $a = new \PHPCompiler\VM\Variable();
        $a->int($seeds[0]);
        $ht->append($a);
        $b = new \PHPCompiler\VM\Variable();
        $b->int($seeds[1]);
        $ht->append($b);

        return $ht;
    }

    public static function phraseToSeedsHashTable(string $phrase): HashTable
    {
        $seeds = VmStatsRandlib::phrtsd($phrase);
        $ht = new HashTable();
        $a = new \PHPCompiler\VM\Variable();
        $a->int($seeds[0]);
        $ht->append($a);
        $b = new \PHPCompiler\VM\Variable();
        $b->int($seeds[1]);
        $ht->append($b);

        return $ht;
    }

    private static function inrgcm(): void
    {
        self::$m1 = 2147483563;
        self::$m2 = 2147483399;
        self::$a1 = 40014;
        self::$a2 = 40692;
        self::$a1w = 1033780774;
        self::$a2w = 1494757890;
        self::$a1vw = 2082007225;
        self::$a2vw = 784306273;
        self::$cg1 = \array_fill(0, self::NUMG, 0);
        self::$cg2 = \array_fill(0, self::NUMG, 0);
        self::$ig1 = \array_fill(0, self::NUMG, 0);
        self::$ig2 = \array_fill(0, self::NUMG, 0);
        self::$lg1 = \array_fill(0, self::NUMG, 0);
        self::$lg2 = \array_fill(0, self::NUMG, 0);
        self::$qanti = \array_fill(0, self::NUMG, 0);
        self::$commonInit = true;
    }

    private static function initgn(int $isdtyp): void
    {
        $g = self::$currentG;
        if (-1 === $isdtyp) {
            self::$lg1[$g - 1] = self::$ig1[$g - 1];
            self::$lg2[$g - 1] = self::$ig2[$g - 1];
        } elseif (1 === $isdtyp) {
            self::$lg1[$g - 1] = self::mltmod(self::$a1w, self::$lg1[$g - 1], self::$m1);
            self::$lg2[$g - 1] = self::mltmod(self::$a2w, self::$lg2[$g - 1], self::$m2);
        }
        self::$cg1[$g - 1] = self::$lg1[$g - 1];
        self::$cg2[$g - 1] = self::$lg2[$g - 1];
    }

    /** (a*s) mod m — PHP 64-bit ints avoid RANLIB 32-bit overflow dance. */
    private static function mltmod(int $a, int $s, int $m): int
    {
        return (int) (($a * $s) % $m);
    }
}
