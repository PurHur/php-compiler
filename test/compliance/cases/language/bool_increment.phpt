--TEST--
language bool pre/post increment (issue #3552, #4727)
--FILE--
<?php
$b = true;
$b++;
echo $b, "\n";
$b = false;
$b++;
echo $b, "\n";
$b = true;
$b--;
echo $b, "\n";
$b = false;
$b--;
echo $b, "\n";
--EXPECT--
1
1
0
-1
