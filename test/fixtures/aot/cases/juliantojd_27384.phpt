--TEST--
AOT: juliantojd() matches Zend (#27384)
--FILE--
<?php
echo juliantojd(1, 1, 2000), PHP_EOL;
$m = 1;
$d = 1;
$y = 2000;
echo juliantojd($m, $d, $y), PHP_EOL;
--EXPECT--
2451558
2451558
