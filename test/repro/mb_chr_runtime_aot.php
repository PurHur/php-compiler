<?php

declare(strict_types=1);

/**
 * mb_chr() runtime (non-literal) args under AOT / NestedJIT.
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_chr) — php_mb_chr encodes valid codepoints.
 */
$n = 0x65;
$jp = 0x65E5;
$bad = -1;
$sur = 0xD800;
$big = 0x110000;
$euro = 0x20AC;

echo 'ascii=', var_export(mb_chr($n), true), "\n";
echo 'jp=', var_export(mb_chr($jp), true), "\n";
echo 'bad=', var_export(mb_chr($bad), true), "\n";
echo 'sur=', var_export(mb_chr($sur), true), "\n";
echo 'big=', var_export(mb_chr($big), true), "\n";
echo 'euro=', var_export(mb_chr($euro), true), "\n";
echo 'lit=', var_export(mb_chr(0x41), true), "\n";
