--TEST--
stdlib strspn()/strcspn() — null haystack coerces to 0 when non-strict (#18264, ext/standard/string.c)
--FILE--
<?php
echo strspn(null, 'abc'), "\n";
echo strcspn(null, 'abc'), "\n";
?>
--EXPECT--
0
0
