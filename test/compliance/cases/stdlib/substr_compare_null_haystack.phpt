--TEST--
stdlib substr_compare(null haystack) — coerce to empty string on default/8.2 profile (#18741, #20164, ext/standard/string.c)
--FILE--
<?php
echo substr_compare(null, 'a', 0), "\n";
?>
--EXPECT--
-1
