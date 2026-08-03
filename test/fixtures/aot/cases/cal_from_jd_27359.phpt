--TEST--
AOT: cal_from_jd() matches Zend (#27359)
--FILE--
<?php
$a = cal_from_jd(2460310, CAL_GREGORIAN);
echo $a["date"], PHP_EOL;
$jd = 2460310;
$b = cal_from_jd($jd, CAL_GREGORIAN);
echo $b["date"], PHP_EOL;
--EXPECT--
12/31/2023
12/31/2023
