--TEST--
AOT: interpolated echo with two script globals after inc/dec (#23842)
--FILE--
<?php
$a = 5;
$a--;
$b = 9;
$b--;
echo "$a $b\n";
?>
--EXPECT--
4 8
