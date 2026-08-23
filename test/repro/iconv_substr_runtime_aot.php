<?php

declare(strict_types=1);

/**
 * iconv_substr() runtime offset/length under AOT NestedJIT (#34272).
 *
 * php-src: ext/iconv/iconv.c — PHP_FUNCTION(iconv_substr)
 */
echo 'lit=', var_export(iconv_substr('über', 1, 2), true), "\n";
$s = 'über';
echo 'str_var=', var_export(iconv_substr($s, 1, 2), true), "\n";
$i = 1;
echo 'off_rt=', var_export(iconv_substr('über', $i, 2), true), "\n";
$l = 2;
echo 'both_rt=', var_export(iconv_substr($s, $i, $l), true), "\n";
echo 'ascii=', var_export(iconv_substr('abcdef', $i, $l), true), "\n";
