--TEST--
AOT: interpolated echo with three script globals after inc/dec (#23842)
--FILE--
<?php
$a = 5;
$a--;
$b = 9;
$b--;
$c = 2;
$c--;
echo "$a $b $c\n";
?>
--EXPECT--
4 8 1
