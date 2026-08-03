--TEST--
AOT: frenchtojd() matches Zend (#27382)
--FILE--
<?php
echo frenchtojd(1, 1, 1), PHP_EOL;
$m = 1;
$d = 1;
$y = 1;
echo frenchtojd($m, $d, $y), PHP_EOL;
--EXPECT--
2375840
2375840
