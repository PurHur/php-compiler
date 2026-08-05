--TEST--
AOT: sprintf %05d / %'#10s match Zend (#26867)
--FILE--
<?php
echo sprintf("%'#10s", "x"), "\n";
echo sprintf("%05d", 42), "\n";
echo vsprintf("%05d", [42]), "\n";
echo sprintf("n=%05d", 7), "\n";
--EXPECT--
#########x
00042
00042
n=00007
