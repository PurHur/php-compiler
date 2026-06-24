--TEST--
stdlib number_format() — null $num coerces to 0 (#11017, ext/standard/number_format.c)
--FILE--
<?php
echo number_format(null), "\n";
--EXPECT--
0
