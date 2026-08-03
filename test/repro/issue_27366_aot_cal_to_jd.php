<?php

declare(strict_types=1);

/**
 * Repro #27366 — AOT cal_to_jd() must match Zend/VM.
 */
echo cal_to_jd(CAL_GREGORIAN, 8, 3, 2026), PHP_EOL;
$y = 2026;
echo cal_to_jd(CAL_GREGORIAN, 8, 3, $y), PHP_EOL;
