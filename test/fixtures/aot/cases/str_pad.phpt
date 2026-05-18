--TEST--
AOT: str_pad() STR_PAD_RIGHT (default) and STR_PAD_LEFT (0)
--FILE--
<?php
echo str_pad('5', 4, '0'), "\n";
echo str_pad('5', 4, '0', 0), "\n";
--EXPECT--
5000
0005
