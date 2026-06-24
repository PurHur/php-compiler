--TEST--
stdlib number_format() JIT — null $num coerces to 0 (#11017, ext/standard/number_format.c)
--JIT--
--FILE--
<?php
echo number_format(null), "\n";
--EXPECT--
0
