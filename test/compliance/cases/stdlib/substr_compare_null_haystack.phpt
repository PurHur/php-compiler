--TEST--
stdlib substr_compare(null haystack) — coerce to empty string (#18741, ext/standard/string.c)
--FILE--
<?php
echo substr_compare(null, 'a', 0), "\n";
?>
--EXPECT--
-1
