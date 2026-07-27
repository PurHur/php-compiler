--TEST--
AOT: number_format() unsigned zero after round-to-zero (#23980, math.c)
--FILE--
<?php
echo number_format(-0.004, 2), "\n";
echo number_format(-0.0004, 2), "\n";
echo number_format(-0.4, 0), "\n";
echo number_format(-0.004, 2, ',', ' '), "\n";
--EXPECT--
0.00
0.00
0
0,00
