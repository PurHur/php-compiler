--TEST--
AOT: interpolated echo with two script globals in one string (#23842, re-#23798)
--FILE--
<?php
$a = 5;
$b = 9;
echo "$a $b\n";
?>
--EXPECT--
5 9
