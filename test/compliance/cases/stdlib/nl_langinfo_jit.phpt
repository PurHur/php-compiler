--TEST--
JIT: nl_langinfo() (#3382, #29459, ext/standard/nl_langinfo.c)
--FILE--
<?php
error_reporting(E_ALL);
setlocale(LC_TIME, 'C');
echo nl_langinfo(DAY_1), "\n";
echo nl_langinfo(CODESET), "\n";
echo var_export(nl_langinfo(999999), true), "\n";
--EXPECTF--
PHP Warning:  nl_langinfo(): Item '999999' is not valid in %s on line %d
Sunday
UTF-8
false
