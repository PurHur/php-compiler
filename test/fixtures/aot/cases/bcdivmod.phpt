--TEST--
AOT: bcdivmod() smoke (#6966)
--FILE--
<?php
$p = bcdivmod('10.5', '3.2', 2);
echo $p[0], "\n";
echo $p[1], "\n";
--EXPECT--
3
0.90
