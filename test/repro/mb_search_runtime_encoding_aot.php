<?php

declare(strict_types=1);

/**
 * #34866 — mb_strpos/mb_strstr family with runtime encoding under thin AOT.
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_strpos|mb_strstr|…)
 *
 * Use concat so encoding is TYPE_VALUE (plain `$e='UTF-8'` may fold).
 */
$e = 'UTF-' . '8';
echo 'pos=', mb_strpos('あい', 'い', 0, $e), "\n";
echo 'strstr=', mb_strstr('あいウ', 'い', false, $e), "\n";
echo 'stripos=', mb_stripos('AbC', 'b', 0, $e), "\n";
echo 'strrpos=', mb_strrpos('ああい', 'あ', 0, $e), "\n";
$ascii = 'ASC' . 'II';
echo 'pos_ascii=', mb_strpos('hello', 'll', 0, $ascii), "\n";
try {
    $bad = 'NO_SUCH_ENCODING';
    echo mb_strpos('a', 'b', 0, $bad);
    echo "no error\n";
} catch (ValueError $err) {
    echo 'err=', $err->getMessage(), "\n";
}
