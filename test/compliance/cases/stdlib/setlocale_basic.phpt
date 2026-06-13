--TEST--
stdlib setlocale()/localeconv()/strcoll() (#6133, ext/standard/locale.c)
--FILE--
<?php
echo function_exists('setlocale') ? '1' : '0';
echo function_exists('localeconv') ? '1' : '0';
echo function_exists('strcoll') ? '1' : '0';
echo "\n";
setlocale(LC_ALL, 'C');
echo setlocale(LC_ALL, null), "\n";
echo strcoll('a', 'b'), "\n";
$lc = localeconv();
echo is_array($lc) ? '1' : '0';
echo isset($lc['decimal_point']) ? '1' : '0';
echo "\n";
echo $lc['decimal_point'], "\n";
--EXPECT--
111
C
-1
11
.
