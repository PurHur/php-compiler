--TEST--
AOT: jdtogregorian() matches Zend (#27355)
--FILE--
<?php
echo jdtogregorian(2460310), PHP_EOL;
$jd = 2460310;
echo jdtogregorian($jd), PHP_EOL;
--EXPECT--
12/31/2023
12/31/2023
