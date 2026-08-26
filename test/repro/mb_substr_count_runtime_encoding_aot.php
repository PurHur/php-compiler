<?php

declare(strict_types=1);

/**
 * #35155 — mb_substr_count() with runtime encoding under thin AOT.
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_substr_count)
 */
$enc = 'UTF-8';
echo 'count=', mb_substr_count('ababab', 'ab', $enc), "\n";
$ascii = 'ASCII';
echo 'ascii=', mb_substr_count('xxx', 'x', $ascii), "\n";
$jp = '日本語日本';
$n = '日本';
$enc2 = 'UTF-8';
echo 'jp=', mb_substr_count($jp, $n, $enc2), "\n";
try {
    $bad = 'nope';
    echo mb_substr_count('x', 'x', $bad);
    echo "no error\n";
} catch (ValueError $e) {
    echo 'bad_enc=', $e->getMessage(), "\n";
}
