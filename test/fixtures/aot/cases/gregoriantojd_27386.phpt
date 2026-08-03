--TEST--
AOT: gregoriantojd() matches Zend (#27386)
--FILE--
<?php
echo gregoriantojd(8, 3, 2026), PHP_EOL;
$m = 8;
$d = 3;
$y = 2026;
echo gregoriantojd($m, $d, $y), PHP_EOL;
--EXPECT--
2461256
2461256
