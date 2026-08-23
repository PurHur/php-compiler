<?php

declare(strict_types=1);

/**
 * mb_chr() runtime (non-literal) args under AOT / NestedJIT (#34250 leftover of #33536).
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_chr)
 */
$ascii = 0x65 + 0;
$jp = 0x65E5 + 0;
$euro = 0x20AC + 0;
$badNeg = 0 - 1;
$badSur = 0xD800 + 0;
$badHi = 0x110000 + 0;
$nullCp = 0 + 0;

echo 'ascii=', var_export(mb_chr($ascii), true), "\n";
echo 'jp=', var_export(mb_chr($jp), true), "\n";
echo 'euro=', var_export(mb_chr($euro), true), "\n";
echo 'badNeg=', var_export(mb_chr($badNeg), true), "\n";
echo 'badSur=', var_export(mb_chr($badSur), true), "\n";
echo 'badHi=', var_export(mb_chr($badHi), true), "\n";
echo 'nul=', var_export(mb_chr($nullCp), true), "\n";
echo 'lit=', var_export(mb_chr(0x41), true), "\n";
