--TEST--
AOT: multi-slot list by-ref destructuring write-through (#34673)
--FILE--
<?php
$a = [1, 2];
[&$x, &$y] = $a;
$x = 9;
echo $a[0], '|', $y, "\n";
--EXPECT--
9|2
