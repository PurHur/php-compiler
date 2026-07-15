--TEST--
stdlib number_format(null) — null coerces to 0 on default profile (#19068, ext/standard/number_format.c)
--FILE--
<?php
echo number_format(null), "\n";
?>
--EXPECT--
0
