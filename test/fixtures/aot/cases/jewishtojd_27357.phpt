--TEST--
AOT: jewishtojd() matches Zend (#27357)
--FILE--
<?php
echo jewishtojd(1, 1, 5784), PHP_EOL;
$m = 1;
$d = 1;
$y = 5784;
echo jewishtojd($m, $d, $y), PHP_EOL;
--EXPECT--
2460204
2460204
