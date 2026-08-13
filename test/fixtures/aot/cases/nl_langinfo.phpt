--TEST--
AOT: nl_langinfo() locale item lookup (#3382, #30789, ext/standard/nl_langinfo.c)
--FILE--
<?php
echo nl_langinfo(DAY_1), "\n";
echo nl_langinfo(CODESET), "\n";
$r = nl_langinfo(999999);
echo ($r === false ? 'false' : $r), "\n";
--EXPECT--
Sunday
UTF-8
false
--EXPECT_EXIT--
0
