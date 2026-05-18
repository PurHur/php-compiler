--TEST--
Not-equal and not-identical operators
--FILE--
<?php
echo (1 !== 2) ? "1\n" : "0\n";
echo (1 !== 1) ? "1\n" : "0\n";
echo (1 != 2) ? "1\n" : "0\n";
echo (1 != 1) ? "1\n" : "0\n";
--EXPECT--
1
0
1
0
