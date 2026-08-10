--TEST--
iconv_strlen/substr/strpos/strrpos empty encoding uses default charset (#29497, ext/iconv/iconv.c)
--FILE--
<?php
error_reporting(E_ALL);
echo var_export(iconv_strlen('abc', ''), true), "\n";
echo var_export(iconv_substr('abc', 0, 1, ''), true), "\n";
echo var_export(iconv_strpos('abc', 'b', 0, ''), true), "\n";
echo var_export(iconv_strrpos('abc', 'b', ''), true), "\n";
echo var_export(iconv_strlen('abc', 'UTF-8'), true), "\n";
?>
--EXPECT--
3
'a'
1
1
3
