--TEST--
JIT: nl_langinfo() (#3382, ext/standard/nl_langinfo.c)
--FILE--
<?php
setlocale(LC_TIME, 'C');
echo nl_langinfo(DAY_1), "\n";
echo nl_langinfo(CODESET), "\n";
echo var_export(nl_langinfo(999999), true), "\n";
--EXPECT--
Sunday
UTF-8
false
