--TEST--
AOT: stristr() via JitStringSearch and haystack slice
--FILE--
<?php
echo stristr("ABC-DEF", "-"), "\n";
echo stristr("ABC-DEF", "-", true), "\n";
--EXPECT--
-DEF
ABC
