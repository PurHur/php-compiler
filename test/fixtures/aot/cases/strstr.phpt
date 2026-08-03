--TEST--
AOT: strstr()/stristr() via VmStringCompare scan + slice (#27185)
--FILE--
<?php
echo strstr("Hello World", "World"), "\n";
echo stristr("Hello World", "WORLD"), "\n";
echo strstr("abc-def", "-"), "\n";
echo strstr("abc-def", "-", true), "\n";
$miss = strstr("abc", "z");
echo ($miss === false ? "false" : "hit"), "\n";
--EXPECT--
World
World
-def
abc
false
