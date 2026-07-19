<?php
/**
 * #20973 — classic bcmath ops soft-null under PHP_COMPILER_PROFILE=8.4.
 * php-src: ext/bcmath/bcmath.c + bcmath.stub.php (string $num1/$num; null deprecate+coerce, "" → 0).
 * Note: issue title asked TypeError; Zend 8.4 soft-nulls — php-src-strict matches Zend (#21006 same shape).
 */
foreach ([
    'bcadd' => static fn () => bcadd(null, '1'),
    'bcsub' => static fn () => bcsub(null, '1'),
    'bcmul' => static fn () => bcmul(null, '1'),
    'bcdiv' => static fn () => bcdiv(null, '1'),
    'bcmod' => static fn () => bcmod(null, '1'),
    'bcpow' => static fn () => bcpow(null, '1'),
    'bcsqrt' => static fn () => bcsqrt(null),
    'bccomp' => static fn () => bccomp(null, '1'),
    'empty' => static fn () => bcadd('', '1'),
    'invalid' => static fn () => bcadd('not-a-number', '1'),
] as $n => $fn) {
    try {
        $r = $fn();
        echo $n, ' => ', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $n, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
