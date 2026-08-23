<?php

declare(strict_types=1);

/**
 * mb_chr() runtime (non-literal) args under AOT / NestedJIT (#34250 leftover of #33536).
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_chr) — php_mb_chr.
 */
$e = 0x65 + 0;
$jp = 0x65E5 + 0;
$neg = -1 + 0;
$surr = 0xD800 + 0;
$hi = 0x110000 + 0;
$euro = 0x20AC + 0;

echo 'e=', var_export(mb_chr($e), true), "\n";
echo 'jp=', var_export(mb_chr($jp), true), "\n";
echo 'neg=', var_export(mb_chr($neg), true), "\n";
echo 'surr=', var_export(mb_chr($surr), true), "\n";
echo 'hi=', var_export(mb_chr($hi), true), "\n";
echo 'euro=', var_export(mb_chr($euro), true), "\n";
echo 'lit=', var_export(mb_chr(0x41), true), "\n";
