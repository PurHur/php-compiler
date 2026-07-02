--TEST--
Language: list()/[] destructuring cannot mix keyed and unkeyed entries — compile-time fatal (#14879)
--FILE--
<?php
list(0 => $x, $y) = [1, 2];
echo "ran\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Cannot mix keyed and unkeyed array entries in assignments in %s on line %d
