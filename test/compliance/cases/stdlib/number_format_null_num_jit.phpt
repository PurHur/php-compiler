--TEST--
stdlib number_format(null) JIT — null coerces to 0 on default profile (#19068, ext/standard/number_format.c)
--JIT--
--FILE--
<?php
echo number_format(null), "\n";
?>
--EXPECT--
0
