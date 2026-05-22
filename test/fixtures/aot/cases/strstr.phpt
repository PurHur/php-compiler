--TEST--
AOT: strstr() via libc strstr and haystack slice
--FILE--
<?php
echo strstr("abc-def", "-"), "\n";
echo strstr("abc-def", "-", true), "\n";
--EXPECT--
-def
abc