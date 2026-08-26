<?php

declare(strict_types=1);

/**
 * #34870 — mb_chr()/mb_ord() with runtime encoding under thin AOT.
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_chr|mb_ord)
 */
$e = 'UTF-'.'8';
echo 'chr=', mb_chr(0x3042, $e), "\n";
echo 'ord=', mb_ord('あ', $e), "\n";
$ascii = 'ASC'.'II';
echo 'chr_ascii=', var_export(mb_chr(0x41, $ascii), true), "\n";
echo 'ord_ascii=', mb_ord('A', $ascii), "\n";
try {
    $bad = 'NOPE';
    echo mb_chr(65, $bad);
    echo "no error\n";
} catch (ValueError $err) {
    echo 'err=', $err->getMessage(), "\n";
}
