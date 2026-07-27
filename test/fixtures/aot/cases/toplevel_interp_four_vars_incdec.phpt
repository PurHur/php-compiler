--TEST--
AOT: interpolated echo with four script globals after inc/dec (#23842)
--FILE--
<?php
$a = 5;
$a--;
$b = 9;
$b--;
$c = 2;
$c--;
$d = 7;
$d--;
echo "$a $b $c $d\n";
?>
--EXPECT--
4 8 1 6
