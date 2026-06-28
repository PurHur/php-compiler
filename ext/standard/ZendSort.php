<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * php-src Zend/zend_sort.c — usort/uasort/uksort need zend_sort not naive insertion (#13029).
 *
 * @internal
 */
final class ZendSort
{
    /**
     * @template T
     *
     * @param list<T> $base
     * @param callable(T, T): int $cmp
     */
    public static function sort(array &$base, callable $cmp): void
    {
        $n = \count($base);
        if ($n < 2) {
            return;
        }
        self::zendSort($base, 0, $n, $cmp);
    }

    /**
     * @template T
     *
     * @param list<T> $base
     * @param callable(T, T): int $cmp
     */
    private static function zendSort(array &$base, int $start, int $nmemb, callable $cmp): void
    {
        while (true) {
            if ($nmemb <= 16) {
                self::insertSort($base, $start, $nmemb, $cmp);

                return;
            }
            $end = $start + $nmemb;
            $offset = intdiv($nmemb, 2);
            $pivotIdx = $start + $offset;
            if ($nmemb >> 10) {
                $delta = intdiv($offset, 2);
                self::sort5(
                    $base,
                    $start,
                    $start + $delta,
                    $pivotIdx,
                    $pivotIdx + $delta,
                    $end - 1,
                    $cmp
                );
            } else {
                self::sort3($base, $start, $pivotIdx, $end - 1, $cmp);
            }
            self::swap($base, $start + 1, $pivotIdx);
            $pivotIdx = $start + 1;
            $i = $pivotIdx + 1;
            $j = $end - 1;
            while (true) {
                while ($cmp($base[$pivotIdx], $base[$i]) > 0) {
                    ++$i;
                    if ($i === $j) {
                        goto done;
                    }
                }
                --$j;
                if ($j === $i) {
                    goto done;
                }
                while ($cmp($base[$j], $base[$pivotIdx]) > 0) {
                    --$j;
                    if ($j === $i) {
                        goto done;
                    }
                }
                self::swap($base, $i, $j);
                ++$i;
                if ($i === $j) {
                    goto done;
                }
            }
            done:
            self::swap($base, $pivotIdx, $i - 1);
            if (($i - 1) - $start < $end - $i) {
                self::zendSort($base, $start, $i - $start - 1, $cmp);
                $start = $i;
                $nmemb = $end - $i;
            } else {
                self::zendSort($base, $i, $end - $i, $cmp);
                $nmemb = $i - $start - 1;
            }
        }
    }

    /**
     * @template T
     *
     * @param list<T> $base
     * @param callable(T, T): int $cmp
     */
    private static function insertSort(array &$base, int $start, int $nmemb, callable $cmp): void
    {
        switch ($nmemb) {
            case 0:
            case 1:
                return;
            case 2:
                self::sort2($base, $start, $start + 1, $cmp);

                return;
            case 3:
                self::sort3($base, $start, $start + 1, $start + 2, $cmp);

                return;
            case 4:
                self::sort4($base, $start, $start + 1, $start + 2, $start + 3, $cmp);

                return;
            case 5:
                self::sort5($base, $start, $start + 1, $start + 2, $start + 3, $start + 4, $cmp);

                return;
            default:
                $end = $start + $nmemb;
                $sentry = $start + 6;
                for ($i = $start + 1; $i < $sentry && $i < $end; ++$i) {
                    $j = $i - 1;
                    if (!($cmp($base[$j], $base[$i]) > 0)) {
                        continue;
                    }
                    while ($j !== $start) {
                        --$j;
                        if (!($cmp($base[$j], $base[$i]) > 0)) {
                            ++$j;
                            break;
                        }
                    }
                    for ($k = $i; $k > $j; --$k) {
                        self::swap($base, $k, $k - 1);
                    }
                }
                for ($i = $sentry; $i < $end; ++$i) {
                    $j = $i - 1;
                    if (!($cmp($base[$j], $base[$i]) > 0)) {
                        continue;
                    }
                    do {
                        $j -= 2;
                        if (!($cmp($base[$j], $base[$i]) > 0)) {
                            $j += 1;
                            if (!($cmp($base[$j], $base[$i]) > 0)) {
                                ++$j;
                            }
                            break;
                        }
                        if ($j === $start) {
                            break;
                        }
                        if ($j === $start + 1) {
                            --$j;
                            if ($cmp($base[$i], $base[$j]) > 0) {
                                ++$j;
                            }
                            break;
                        }
                    } while (true);
                    for ($k = $i; $k > $j; --$k) {
                        self::swap($base, $k, $k - 1);
                    }
                }
        }
    }

    /** @param list<mixed> $base */
    private static function sort2(array &$base, int $a, int $b, callable $cmp): void
    {
        if ($cmp($base[$a], $base[$b]) > 0) {
            self::swap($base, $a, $b);
        }
    }

    /** @param list<mixed> $base */
    private static function sort3(array &$base, int $a, int $b, int $c, callable $cmp): void
    {
        if (!($cmp($base[$a], $base[$b]) > 0)) {
            if (!($cmp($base[$b], $base[$c]) > 0)) {
                return;
            }
            self::swap($base, $b, $c);
            if ($cmp($base[$a], $base[$b]) > 0) {
                self::swap($base, $a, $b);
            }

            return;
        }
        if (!($cmp($base[$c], $base[$b]) > 0)) {
            self::swap($base, $a, $c);

            return;
        }
        self::swap($base, $a, $b);
        if ($cmp($base[$b], $base[$c]) > 0) {
            self::swap($base, $b, $c);
        }
    }

    /** @param list<mixed> $base */
    private static function sort4(array &$base, int $a, int $b, int $c, int $d, callable $cmp): void
    {
        self::sort3($base, $a, $b, $c, $cmp);
        if ($cmp($base[$c], $base[$d]) > 0) {
            self::swap($base, $c, $d);
            if ($cmp($base[$b], $base[$c]) > 0) {
                self::swap($base, $b, $c);
                if ($cmp($base[$a], $base[$b]) > 0) {
                    self::swap($base, $a, $b);
                }
            }
        }
    }

    /** @param list<mixed> $base */
    private static function sort5(array &$base, int $a, int $b, int $c, int $d, int $e, callable $cmp): void
    {
        self::sort4($base, $a, $b, $c, $d, $cmp);
        if ($cmp($base[$d], $base[$e]) > 0) {
            self::swap($base, $d, $e);
            if ($cmp($base[$c], $base[$d]) > 0) {
                self::swap($base, $c, $d);
                if ($cmp($base[$b], $base[$c]) > 0) {
                    self::swap($base, $b, $c);
                    if ($cmp($base[$a], $base[$b]) > 0) {
                        self::swap($base, $a, $b);
                    }
                }
            }
        }
    }

    /** @param list<mixed> $base */
    private static function swap(array &$base, int $a, int $b): void
    {
        $tmp = $base[$a];
        $base[$a] = $base[$b];
        $base[$b] = $tmp;
    }
}
