<?php
/**
 * #21006 — bcround/bcceil/bcfloor(null) soft-null under PHP_COMPILER_PROFILE=8.4.
 * php-src: ext/bcmath/bcmath.c + bcmath.stub.php (string $num; null deprecate+coerce, "" → 0).
 * Note: issue title asked TypeError; Zend 8.4 soft-nulls — php-src-strict matches Zend.
 */
foreach ([
    'bcround' => static fn () => bcround(null, 0),
    'bcceil' => static fn () => bcceil(null),
    'bcfloor' => static fn () => bcfloor(null),
    'empty_round' => static fn () => bcround('', 0),
    'invalid' => static fn () => bcround('not-a-number', 0),
] as $n => $fn) {
    try {
        $r = $fn();
        echo $n, ' => ', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $n, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
