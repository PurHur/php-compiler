<?php

declare(strict_types=1);

/**
 * #34875 — mb_substr()/mb_strcut() with runtime encoding under thin AOT.
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_substr|mb_strcut)
 *
 * Use concat so encoding is TYPE_VALUE (plain `$e='UTF-8'` may fold).
 */
$e = 'UTF-' . '8';
echo 'substr=', mb_substr('あいう', 1, 1, $e), "\n";
echo 'strcut=', mb_strcut('hello world', 6, 5, $e), "\n";
$ascii = 'ASC' . 'II';
echo 'substr_ascii=', mb_substr('hello', 1, 2, $ascii), "\n";
try {
    $bad = 'NO_SUCH_ENCODING';
    echo mb_substr('a', 0, 1, $bad);
    echo "no error\n";
} catch (ValueError $err) {
    echo 'err=', $err->getMessage(), "\n";
}
try {
    echo mb_strcut('a', 0, 1, 'NOPE');
    echo "no error2\n";
} catch (ValueError $err) {
    echo 'err2=', $err->getMessage(), "\n";
}
