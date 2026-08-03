<?php

declare(strict_types=1);

/**
 * Repro #27359 — AOT cal_from_jd() must match Zend/VM.
 */
$a = cal_from_jd(2460310, CAL_GREGORIAN);
echo $a['date'], PHP_EOL;
$jd = 2460310;
$b = cal_from_jd($jd, CAL_GREGORIAN);
echo $b['date'], PHP_EOL;
