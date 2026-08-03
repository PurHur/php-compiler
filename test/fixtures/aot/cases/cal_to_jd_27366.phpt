--TEST--
AOT: cal_to_jd() matches Zend (#27366)
--FILE--
<?php
echo cal_to_jd(CAL_GREGORIAN, 8, 3, 2026), PHP_EOL;
$y = 2026;
echo cal_to_jd(CAL_GREGORIAN, 8, 3, $y), PHP_EOL;
--EXPECT--
2461256
2461256
