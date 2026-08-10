--TEST--
stdlib nl_langinfo() locale item lookup (#3382, #29459, ext/standard/nl_langinfo.c)
--FILE--
<?php
error_reporting(E_ALL);
echo function_exists('nl_langinfo') ? '1' : '0';
echo "\n";
setlocale(LC_TIME, 'C');
echo nl_langinfo(DAY_1), "\n";
echo nl_langinfo(CODESET), "\n";
echo nl_langinfo(ABDAY_1), "\n";
echo var_export(nl_langinfo(999999), true), "\n";
--EXPECTF--
PHP Warning:  nl_langinfo(): Item '999999' is not valid in %s on line %d
1
Sunday
UTF-8
Sun
false
