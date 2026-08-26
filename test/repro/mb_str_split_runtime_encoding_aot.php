<?php

declare(strict_types=1);

/**
 * #34880 — mb_str_split() with runtime encoding under thin AOT.
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_str_split)
 *
 * Use concat so encoding is TYPE_VALUE (plain `$e='UTF-8'` may fold).
 */
$e = 'UTF-' . '8';
$parts = mb_str_split('あいう', 1, $e);
echo 'utf8=', implode(',', $parts), "\n";
$ascii = 'ASC' . 'II';
$parts2 = mb_str_split('ab', 1, $ascii);
echo 'ascii=', implode(',', $parts2), "\n";
try {
    $bad = 'NO_SUCH_ENCODING';
    mb_str_split('a', 1, $bad);
    echo "no error\n";
} catch (ValueError $err) {
    echo 'err=', $err->getMessage(), "\n";
}
