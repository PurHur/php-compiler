<?php

declare(strict_types=1);

/**
 * #34858 — mb_strtolower/toupper with runtime encoding under thin AOT.
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_strtolower|mb_strtoupper)
 *
 * mb_ucfirst/mb_lcfirst (PHP 8.4+) are covered by the same JitMbUcfirstLcfirst path;
 * this repro stays on 8.2-available APIs for Zend byte-compare.
 */
$e = 'UTF-8';
echo 'low=', mb_strtolower('Ä', $e), "\n";
echo 'up=', mb_strtoupper('ä', $e), "\n";
$ascii = 'ASCII';
echo 'low_ascii=', mb_strtolower('AbC', $ascii), "\n";
try {
    $bad = 'NO_SUCH_ENCODING';
    echo mb_strtolower('x', $bad);
    echo "no error\n";
} catch (ValueError $err) {
    echo 'err=', $err->getMessage(), "\n";
}
