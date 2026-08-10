<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

/**
 * RANLIB algorithm ports for pecl-stats rand generators (#29622).
 *
 * php-src: pecl-math-stats randlib.c (genbet/sexpo/snorm/sgamma/phrtsd).
 * Consumes {@see VmStatsRand::ranf()} / ignlgi state.
 */
final class VmStatsRandlib
{
    private const EXPMAX = 87.49823;
    private const INFNTY = 1.0E38;
    private const MINLOG = 1.0E-37;

    private static float $betOldA = -1.0E37;
    private static float $betOldB = -1.0E37;
    private static float $betA = 0.0;
    private static float $betB = 0.0;
    private static float $betAlpha = 0.0;
    private static float $betBeta = 0.0;
    private static float $betGamma = 0.0;
    private static float $betDelta = 0.0;
    private static float $betK1 = 0.0;
    private static float $betK2 = 0.0;

    private static float $sgAa = 0.0;
    private static float $sgAaa = 0.0;
    private static float $sgS2 = 0.0;
    private static float $sgS = 0.0;
    private static float $sgD = 0.0;
    private static float $sgQ0 = 0.0;
    private static float $sgB = 0.0;
    private static float $sgSi = 0.0;
    private static float $sgC = 0.0;

    /** Reset Cheng/sgamma/ignbin caches when setall() reseeds (#29622, #29649). */
    public static function resetCaches(): void
    {
        self::$betOldA = -1.0E37;
        self::$betOldB = -1.0E37;
        self::$sgAa = 0.0;
        self::$sgAaa = 0.0;
        self::$binPsave = -1.0E37;
        self::$binNsave = -214748365;
    }

    /** RANLIB genchi(df) = 2*sgamma(df/2) (#29649). */
    public static function genchi(float $df): float
    {
        return 2.0 * self::sgamma($df / 2.0);
    }

    /** RANLIB genf(dfn,dfd) — ratio of chisquare variates (#29649). */
    public static function genf(float $dfn, float $dfd): float
    {
        $xnum = 2.0 * self::sgamma($dfn / 2.0) / $dfn;
        $xden = 2.0 * self::sgamma($dfd / 2.0) / $dfd;
        if ($xden <= 1.0E-37 * $xnum) {
            return 1.0E37;
        }

        return $xnum / $xden;
    }

    /** RANLIB genunf(low,high) (#29649). */
    public static function genunf(float $low, float $high): float
    {
        return $low + ($high - $low) * VmStatsRand::ranf();
    }

    private static float $binPsave = -1.0E37;
    private static int $binNsave = -214748365;
    private static float $binP = 0.0;
    private static float $binQ = 0.0;
    private static float $binXnp = 0.0;
    private static float $binFfM = 0.0;
    private static int $binM = 0;
    private static float $binFm = 0.0;
    private static float $binXnpq = 0.0;
    private static float $binP1 = 0.0;
    private static float $binXm = 0.0;
    private static float $binXl = 0.0;
    private static float $binXr = 0.0;
    private static float $binC = 0.0;
    private static float $binXll = 0.0;
    private static float $binXlr = 0.0;
    private static float $binP2 = 0.0;
    private static float $binP3 = 0.0;
    private static float $binP4 = 0.0;
    private static float $binQn = 0.0;
    private static float $binR = 0.0;
    private static float $binG = 0.0;

    /**
     * RANLIB ignbin(n,pp) — BTPE / inverse-CDF binomial (#29649).
     *
     * Preconditions: n ≥ 0, 0 ≤ pp ≤ 1 (checked by caller).
     */
    public static function ignbin(int $n, float $pp): int
    {
        if ($pp !== self::$binPsave) {
            self::$binPsave = $pp;
            self::$binP = \min($pp, 1.0 - $pp);
            self::$binQ = 1.0 - self::$binP;
            self::$binNsave = -214748365; // force n setup
        }
        if ($n !== self::$binNsave) {
            self::$binXnp = $n * self::$binP;
            self::$binNsave = $n;
            if (self::$binXnp >= 30.0) {
                self::$binFfM = self::$binXnp + self::$binP;
                self::$binM = (int) self::$binFfM;
                self::$binFm = (float) self::$binM;
                self::$binXnpq = self::$binXnp * self::$binQ;
                self::$binP1 = (float) ((int) (2.195 * \sqrt(self::$binXnpq) - 4.6 * self::$binQ)) + 0.5;
                self::$binXm = self::$binFm + 0.5;
                self::$binXl = self::$binXm - self::$binP1;
                self::$binXr = self::$binXm + self::$binP1;
                self::$binC = 0.134 + 20.5 / (15.3 + self::$binFm);
                $al = (self::$binFfM - self::$binXl) / (self::$binFfM - self::$binXl * self::$binP);
                self::$binXll = $al * (1.0 + 0.5 * $al);
                $al = (self::$binXr - self::$binFfM) / (self::$binXr * self::$binQ);
                self::$binXlr = $al * (1.0 + 0.5 * $al);
                self::$binP2 = self::$binP1 * (1.0 + self::$binC + self::$binC);
                self::$binP3 = self::$binP2 + self::$binC / self::$binXll;
                self::$binP4 = self::$binP3 + self::$binC / self::$binXlr;
            } else {
                self::$binQn = self::$binQ ** $n;
                self::$binR = self::$binP / self::$binQ;
                self::$binG = self::$binR * ($n + 1);
            }
        }

        if (self::$binXnp < 30.0) {
            while (true) {
                $ix = 0;
                $f = self::$binQn;
                $u = VmStatsRand::ranf();
                while (true) {
                    if ($u < $f) {
                        break 2;
                    }
                    if ($ix > 110) {
                        break;
                    }
                    $u -= $f;
                    ++$ix;
                    $f *= (self::$binG / $ix - self::$binR);
                }
            }
        } else {
            while (true) {
                $u = VmStatsRand::ranf() * self::$binP4;
                $v = VmStatsRand::ranf();
                if ($u <= self::$binP1) {
                    $ix = (int) (self::$binXm - self::$binP1 * $v + $u);
                    break;
                }
                if ($u <= self::$binP2) {
                    $x = self::$binXl + ($u - self::$binP1) / self::$binC;
                    $v = $v * self::$binC + 1.0 - \abs(self::$binXm - $x) / self::$binP1;
                    if ($v > 1.0 || $v <= 0.0) {
                        continue;
                    }
                    $ix = (int) $x;
                } elseif ($u <= self::$binP3) {
                    $ix = (int) (self::$binXl + \log($v) / self::$binXll);
                    if ($ix < 0) {
                        continue;
                    }
                    $v *= (($u - self::$binP2) * self::$binXll);
                } else {
                    $ix = (int) (self::$binXr - \log($v) / self::$binXlr);
                    if ($ix > $n) {
                        continue;
                    }
                    $v *= (($u - self::$binP3) * self::$binXlr);
                }
                $k = \abs($ix - self::$binM);
                if ($k > 20 && $k < self::$binXnpq / 2.0 - 1.0) {
                    $amaxp = $k / self::$binXnpq * (($k * ($k / 3.0 + 0.625) + 0.1666666666666)
                        / self::$binXnpq + 0.5);
                    $ynorm = -($k * $k / (2.0 * self::$binXnpq));
                    $alv = \log($v);
                    if ($alv < $ynorm - $amaxp) {
                        break;
                    }
                    if ($alv > $ynorm + $amaxp) {
                        continue;
                    }
                    $x1 = $ix + 1.0;
                    $f1 = self::$binFm + 1.0;
                    $z = $n + 1.0 - self::$binFm;
                    $w = $n - $ix + 1.0;
                    $z2 = $z * $z;
                    $x2 = $x1 * $x1;
                    $f2 = $f1 * $f1;
                    $w2 = $w * $w;
                    if ($alv <= self::$binXm * \log($f1 / $x1)
                        + ($n - self::$binM + 0.5) * \log($z / $w)
                        + ($ix - self::$binM) * \log($w * self::$binP / ($x1 * self::$binQ))
                        + (13860.0 - (462.0 - (132.0 - (99.0 - 140.0 / $f2) / $f2) / $f2) / $f2)
                            / $f1 / 166320.0
                        + (13860.0 - (462.0 - (132.0 - (99.0 - 140.0 / $z2) / $z2) / $z2) / $z2)
                            / $z / 166320.0
                        + (13860.0 - (462.0 - (132.0 - (99.0 - 140.0 / $x2) / $x2) / $x2) / $x2)
                            / $x1 / 166320.0
                        + (13860.0 - (462.0 - (132.0 - (99.0 - 140.0 / $w2) / $w2) / $w2) / $w2)
                            / $w / 166320.0) {
                        break;
                    }
                    continue;
                }
                $f = 1.0;
                $r = self::$binP / self::$binQ;
                $g = ($n + 1) * $r;
                $t1 = self::$binM - $ix;
                if ($t1 < 0) {
                    $mp = self::$binM + 1;
                    for ($i = $mp; $i <= $ix; ++$i) {
                        $f *= ($g / $i - $r);
                    }
                } elseif ($t1 > 0) {
                    $ix1 = $ix + 1;
                    for ($i = $ix1; $i <= self::$binM; ++$i) {
                        $f /= ($g / $i - $r);
                    }
                }
                if ($v <= $f) {
                    break;
                }
            }
        }

        if (self::$binPsave > 0.5) {
            $ix = $n - $ix;
        }

        return $ix;
    }

    /** RANLIB sexpo() — Ahrens–Dieter SA. */
    public static function sexpo(): float
    {
        static $q = [
            0.6931472, 0.9333737, 0.9888778, 0.9984959, 0.9998293, 0.9999833, 0.9999986, 0.9999999,
        ];
        $a = 0.0;
        $u = VmStatsRand::ranf();
        while (true) {
            $u += $u;
            if ($u < 1.0) {
                $a += $q[0];
                continue;
            }
            $u -= 1.0;
            if ($u <= $q[0]) {
                return $a + $u;
            }
            $i = 1;
            $ustar = VmStatsRand::ranf();
            $umin = $ustar;
            while (true) {
                $ustar = VmStatsRand::ranf();
                if ($ustar < $umin) {
                    $umin = $ustar;
                }
                ++$i;
                if ($u <= $q[$i - 1]) {
                    return $a + $umin * $q[0];
                }
            }
        }
    }

    /** RANLIB snorm() — Ahrens–Dieter FL (M=5). */
    public static function snorm(): float
    {
        static $a = [
            0.0, 3.917609E-2, 7.841241E-2, 0.11777, 0.1573107, 0.1970991, 0.2372021, 0.2776904,
            0.3186394, 0.36013, 0.4022501, 0.4450965, 0.4887764, 0.5334097, 0.5791322,
            0.626099, 0.6744898, 0.7245144, 0.7764218, 0.8305109, 0.8871466, 0.9467818,
            1.00999, 1.077516, 1.150349, 1.229859, 1.318011, 1.417797, 1.534121, 1.67594,
            1.862732, 2.153875,
        ];
        static $d = [
            0.0, 0.0, 0.0, 0.0, 0.0, 0.2636843, 0.2425085, 0.2255674, 0.2116342, 0.1999243,
            0.1899108, 0.1812252, 0.1736014, 0.1668419, 0.1607967, 0.1553497, 0.1504094,
            0.1459026, 0.14177, 0.1379632, 0.1344418, 0.1311722, 0.128126, 0.1252791,
            0.1226109, 0.1201036, 0.1177417, 0.1155119, 0.1134023, 0.1114027, 0.1095039,
        ];
        static $t = [
            7.673828E-4, 2.30687E-3, 3.860618E-3, 5.438454E-3, 7.0507E-3, 8.708396E-3,
            1.042357E-2, 1.220953E-2, 1.408125E-2, 1.605579E-2, 1.81529E-2, 2.039573E-2,
            2.281177E-2, 2.543407E-2, 2.830296E-2, 3.146822E-2, 3.499233E-2, 3.895483E-2,
            4.345878E-2, 4.864035E-2, 5.468334E-2, 6.184222E-2, 7.047983E-2, 8.113195E-2,
            9.462444E-2, 0.1123001, 0.136498, 0.1716886, 0.2276241, 0.330498, 0.5847031,
        ];
        static $h = [
            3.920617E-2, 3.932705E-2, 3.951E-2, 3.975703E-2, 4.007093E-2, 4.045533E-2,
            4.091481E-2, 4.145507E-2, 4.208311E-2, 4.280748E-2, 4.363863E-2, 4.458932E-2,
            4.567523E-2, 4.691571E-2, 4.833487E-2, 4.996298E-2, 5.183859E-2, 5.401138E-2,
            5.654656E-2, 5.95313E-2, 6.308489E-2, 6.737503E-2, 7.264544E-2, 7.926471E-2,
            8.781922E-2, 9.930398E-2, 0.11556, 0.1404344, 0.1836142, 0.2790016, 0.7010474,
        ];

        $u = VmStatsRand::ranf();
        $s = 0.0;
        if ($u > 0.5) {
            $s = 1.0;
        }
        $u += ($u - $s);
        $u = 32.0 * $u;
        $i = (int) $u;
        if (32 === $i) {
            $i = 31;
        }
        if (0 === $i) {
            $i = 6;
            $aa = $a[31];
            while (true) {
                $u += $u;
                if ($u < 1.0) {
                    $aa += $d[$i - 1];
                    ++$i;
                    continue;
                }
                $u -= 1.0;
                while (true) {
                    $w = $u * $d[$i - 1];
                    $tt = (0.5 * $w + $aa) * $w;
                    while (true) {
                        $ustar = VmStatsRand::ranf();
                        if ($ustar > $tt) {
                            $y = $aa + $w;

                            return 1.0 === $s ? -$y : $y;
                        }
                        $u = VmStatsRand::ranf();
                        if ($ustar >= $u) {
                            $tt = $u;
                            continue;
                        }
                        $u = VmStatsRand::ranf();
                        break;
                    }
                }
            }
        }

        $ustar = $u - (float) $i;
        $aa = $a[$i - 1];
        while (true) {
            if ($ustar <= $t[$i - 1]) {
                $u = VmStatsRand::ranf();
                $w = $u * ($a[$i] - $aa);
                $tt = (0.5 * $w + $aa) * $w;
                while (true) {
                    $ustar2 = VmStatsRand::ranf();
                    if ($ustar2 > $tt) {
                        $y = $aa + $w;

                        return 1.0 === $s ? -$y : $y;
                    }
                    $u = VmStatsRand::ranf();
                    if ($ustar2 >= $u) {
                        $tt = $u;
                        continue;
                    }
                    $ustar = VmStatsRand::ranf();
                    break;
                }
                continue;
            }
            $w = ($ustar - $t[$i - 1]) * $h[$i - 1];
            $y = $aa + $w;

            return 1.0 === $s ? -$y : $y;
        }
    }

    /** RANLIB sgamma(a) — GD for a≥1, GS for 0<a<1. */
    public static function sgamma(float $a): float
    {
        static $q1 = 4.166669E-2;
        static $q2 = 2.083148E-2;
        static $q3 = 8.01191E-3;
        static $q4 = 1.44121E-3;
        static $q5 = -7.388E-5;
        static $q6 = 2.4511E-4;
        static $q7 = 2.424E-4;
        static $a1 = 0.3333333;
        static $a2 = -0.250003;
        static $a3 = 0.2000062;
        static $a4 = -0.1662921;
        static $a5 = 0.1423657;
        static $a6 = -0.1367177;
        static $a7 = 0.1233795;
        static $e1 = 1.0;
        static $e2 = 0.4999897;
        static $e3 = 0.166829;
        static $e4 = 4.07753E-2;
        static $e5 = 1.0293E-2;
        static $sqrt32 = 5.656854;

        if ($a < 1.0) {
            $b0 = 1.0 + 0.3678794 * $a;
            while (true) {
                $p = $b0 * VmStatsRand::ranf();
                if ($p >= 1.0) {
                    $sgamma = -\log(($b0 - $p) / $a);
                    if (self::sexpo() < (1.0 - $a) * \log($sgamma)) {
                        continue;
                    }

                    return $sgamma;
                }
                $sgamma = \exp(\log($p) / $a);
                if (self::sexpo() < $sgamma) {
                    continue;
                }

                return $sgamma;
            }
        }

        if ($a !== self::$sgAa) {
            self::$sgAa = $a;
            self::$sgS2 = $a - 0.5;
            self::$sgS = \sqrt(self::$sgS2);
            self::$sgD = $sqrt32 - 12.0 * self::$sgS;
        }
        $t = self::snorm();
        $x = self::$sgS + 0.5 * $t;
        $sgamma = $x * $x;
        if ($t >= 0.0) {
            return $sgamma;
        }
        $u = VmStatsRand::ranf();
        if (self::$sgD * $u <= $t * $t * $t) {
            return $sgamma;
        }
        if ($a !== self::$sgAaa) {
            self::$sgAaa = $a;
            $r = 1.0 / $a;
            self::$sgQ0 = (((((( $q7 * $r + $q6) * $r + $q5) * $r + $q4) * $r + $q3) * $r + $q2) * $r + $q1) * $r;
            if ($a <= 3.686) {
                self::$sgB = 0.463 + self::$sgS + 0.178 * self::$sgS2;
                self::$sgSi = 1.235;
                self::$sgC = 0.195 / self::$sgS - 7.9E-2 + 1.6E-1 * self::$sgS;
            } elseif ($a <= 13.022) {
                self::$sgB = 1.654 + 7.6E-3 * self::$sgS2;
                self::$sgSi = 1.68 / self::$sgS + 0.275;
                self::$sgC = 6.2E-2 / self::$sgS + 2.4E-2;
            } else {
                self::$sgB = 1.77;
                self::$sgSi = 0.75;
                self::$sgC = 0.1515 / self::$sgS;
            }
        }
        if ($x > 0.0) {
            $v = $t / (self::$sgS + self::$sgS);
            if (\abs($v) <= 0.25) {
                $q = self::$sgQ0 + 0.5 * $t * $t * (((((( $a7 * $v + $a6) * $v + $a5) * $v + $a4) * $v + $a3) * $v + $a2) * $v + $a1) * $v;
            } else {
                $q = self::$sgQ0 - self::$sgS * $t + 0.25 * $t * $t + (self::$sgS2 + self::$sgS2) * \log(1.0 + $v);
            }
            if (\log(1.0 - $u) <= $q) {
                return $sgamma;
            }
        }
        while (true) {
            $e = self::sexpo();
            $u = VmStatsRand::ranf();
            $u += ($u - 1.0);
            $t = self::$sgB + self::fsign(self::$sgSi * $e, $u);
            if ($t < -0.7187449) {
                continue;
            }
            $v = $t / (self::$sgS + self::$sgS);
            if (\abs($v) <= 0.25) {
                $q = self::$sgQ0 + 0.5 * $t * $t * (((((( $a7 * $v + $a6) * $v + $a5) * $v + $a4) * $v + $a3) * $v + $a2) * $v + $a1) * $v;
            } else {
                $q = self::$sgQ0 - self::$sgS * $t + 0.25 * $t * $t + (self::$sgS2 + self::$sgS2) * \log(1.0 + $v);
            }
            if ($q <= 0.0) {
                continue;
            }
            if ($q <= 0.5) {
                $w = (((( $e5 * $q + $e4) * $q + $e3) * $q + $e2) * $q + $e1) * $q;
            } elseif ($q < 15.0) {
                $w = \exp($q) - 1.0;
            } else {
                if (($q + $e - 0.5 * $t * $t) > self::EXPMAX) {
                    $x = self::$sgS + 0.5 * $t;

                    return $x * $x;
                }
                if (self::$sgC * \abs($u) > \exp($q + $e - 0.5 * $t * $t)) {
                    continue;
                }
                $x = self::$sgS + 0.5 * $t;

                return $x * $x;
            }
            if (self::$sgC * \abs($u) > $w * \exp($e - 0.5 * $t * $t)) {
                continue;
            }
            $x = self::$sgS + 0.5 * $t;

            return $x * $x;
        }
    }

    /** RANLIB genbet — Cheng BB/BC. */
    public static function genbet(float $aa, float $bb): float
    {
        $qsame = self::$betOldA === $aa && self::$betOldB === $bb;
        if (!$qsame) {
            self::$betOldA = $aa;
            self::$betOldB = $bb;
        }
        if (\min($aa, $bb) > 1.0) {
            if (!$qsame) {
                self::$betA = \min($aa, $bb);
                self::$betB = \max($aa, $bb);
                self::$betAlpha = self::$betA + self::$betB;
                self::$betBeta = \sqrt((self::$betAlpha - 2.0) / (2.0 * self::$betA * self::$betB - self::$betAlpha));
                self::$betGamma = self::$betA + 1.0 / self::$betBeta;
            }
            while (true) {
                $u1 = VmStatsRand::ranf();
                $u2 = VmStatsRand::ranf();
                $v = self::$betBeta * \log($u1 / (1.0 - $u1));
                if ($v > self::EXPMAX) {
                    $w = self::INFNTY;
                } else {
                    $w = \exp($v);
                    if ($w > self::INFNTY / self::$betA) {
                        $w = self::INFNTY;
                    } else {
                        $w *= self::$betA;
                    }
                }
                $z = ($u1 ** 2.0) * $u2;
                $r = self::$betGamma * $v - 1.3862944;
                $s = self::$betA + $r - $w;
                if ($s + 2.609438 < 5.0 * $z) {
                    $t = \log($z);
                    if ($s <= $t) {
                        if (self::$betAlpha / (self::$betB + $w) < self::MINLOG) {
                            continue;
                        }
                        if ($r + self::$betAlpha * \log(self::$betAlpha / (self::$betB + $w)) < $t) {
                            continue;
                        }
                    }
                }
                if ($aa === self::$betA) {
                    return $w / (self::$betB + $w);
                }

                return self::$betB / (self::$betB + $w);
            }
        }
        if (!$qsame) {
            self::$betA = \max($aa, $bb);
            self::$betB = \min($aa, $bb);
            self::$betAlpha = self::$betA + self::$betB;
            self::$betBeta = 1.0 / self::$betB;
            self::$betDelta = 1.0 + self::$betA - self::$betB;
            self::$betK1 = self::$betDelta * (1.38889E-2 + 4.16667E-2 * self::$betB)
                / (self::$betA * self::$betBeta - 0.777778);
            self::$betK2 = 0.25 + (0.5 + 0.25 / self::$betDelta) * self::$betB;
        }
        while (true) {
            $u1 = VmStatsRand::ranf();
            $u2 = VmStatsRand::ranf();
            if ($u1 < 0.5) {
                $y = $u1 * $u2;
                $z = $u1 * $y;
                if (0.25 * $u2 + $z - $y >= self::$betK1) {
                    continue;
                }
            } else {
                $z = ($u1 ** 2.0) * $u2;
                if ($z <= 0.25) {
                    $v = self::$betBeta * \log($u1 / (1.0 - $u1));
                    $w = self::betExpW($v);
                    if (self::$betA === $aa) {
                        return $w / (self::$betB + $w);
                    }

                    return self::$betB / (self::$betB + $w);
                }
                if ($z >= self::$betK2) {
                    continue;
                }
            }
            $v = self::$betBeta * \log($u1 / (1.0 - $u1));
            $w = self::betExpW($v);
            if (self::$betAlpha / (self::$betB + $w) < self::MINLOG) {
                continue;
            }
            if (self::$betAlpha * (\log(self::$betAlpha / (self::$betB + $w)) + $v) - 1.3862944 < \log($z)) {
                continue;
            }
            if (self::$betA === $aa) {
                return $w / (self::$betB + $w);
            }

            return self::$betB / (self::$betB + $w);
        }
    }

    /** @return array{0: int, 1: int} */
    public static function phrtsd(string $phrase): array
    {
        $table = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+[];:'\\\"<>?,./";
        $twop30 = 1073741824;
        $shift = [1, 64, 4096, 262144, 16777216];
        $seed1 = 1234567890;
        $seed2 = 123456789;
        $iNb = -1;
        $len = \strlen($phrase);
        for ($i = 0; $i < $len; ++$i) {
            if (' ' !== $phrase[$i]) {
                $iNb = $i;
            }
        }
        $lphr = $iNb + 1;
        if ($lphr < 1) {
            return [$seed1, $seed2];
        }
        $tlen = \strlen($table);
        for ($i = 0; $i < $lphr; ++$i) {
            $ix = 0;
            while ($ix < $tlen && $table[$ix] !== $phrase[$i]) {
                ++$ix;
            }
            ++$ix;
            if ($ix >= $tlen) {
                $ix = 0;
            }
            $ichr = $ix % 64;
            if (0 === $ichr) {
                $ichr = 63;
            }
            $values = [];
            for ($j = 1; $j <= 5; ++$j) {
                $values[$j - 1] = $ichr - $j;
                if ($values[$j - 1] < 1) {
                    $values[$j - 1] += 63;
                }
            }
            for ($j = 1; $j <= 5; ++$j) {
                $seed1 = ($seed1 + $shift[$j - 1] * $values[$j - 1]) % $twop30;
                $seed2 = ($seed2 + $shift[$j - 1] * $values[5 - $j]) % $twop30;
            }
        }

        return [$seed1, $seed2];
    }

    private static function betExpW(float $v): float
    {
        $a = self::$betA;
        if ($a > 1.0) {
            if ($v > self::EXPMAX) {
                return self::INFNTY;
            }
            $w = \exp($v);
            if ($w > self::INFNTY / $a) {
                return self::INFNTY;
            }

            return $w * $a;
        }
        if ($v > self::EXPMAX) {
            $w = $v + \log($a);
            if ($w > self::EXPMAX) {
                return self::INFNTY;
            }

            return \exp($w);
        }

        return $a * \exp($v);
    }

    private static function fsign(float $num, float $sign): float
    {
        if (($sign > 0.0 && $num < 0.0) || ($sign < 0.0 && $num > 0.0)) {
            return -$num;
        }

        return $num;
    }
}
