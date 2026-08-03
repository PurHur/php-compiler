--TEST--
AOT: jdmonthname() matches Zend (#27360)
--FILE--
<?php
echo jdmonthname(2460310, 1), PHP_EOL;
$jd = 2460310;
$mode = 1;
echo jdmonthname($jd, $mode), PHP_EOL;
--EXPECT--
December
December
