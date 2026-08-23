<?php

declare(strict_types=1);

/**
 * #34166 — mb_strrpos() runtime (non-literal) args under AOT / NestedJIT.
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_strrpos)
 */

$h = 'abac';
$n = 'a';
$miss = 'z';

echo 'hit=', var_export(mb_strrpos($h, $n), true), "\n";
echo 'miss=', var_export(mb_strrpos($h, $miss), true), "\n";
echo 'lit=', var_export(mb_strrpos('abac', 'a'), true), "\n";
$jp = '日本語';
echo 'jp=', var_export(mb_strrpos($jp, '本'), true), "\n";
