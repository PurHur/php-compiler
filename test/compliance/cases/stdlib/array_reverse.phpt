--TEST--
stdlib array_reverse()
--FILE--
<?php
$a = [1, 2, 3];
$b = array_reverse($a);
echo $a[0], $a[2], "\n";
echo $b[0], $b[2], "\n";
--EXPECT--
13
31
