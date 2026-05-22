--TEST--
AOT: stristr() via libc strcasestr and haystack slice
--FILE--
<?php
echo stristr("ABC-DEF", "-"), "\n";
echo stristr("ABC-DEF", "-", true), "\n";
--EXPECT--
-DEF
ABC
