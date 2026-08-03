<?php

declare(strict_types=1);

/**
 * Repro #27360 — AOT jdmonthname() must match Zend/VM.
 */
echo jdmonthname(2460310, 1), PHP_EOL;
$jd = 2460310;
$mode = 1;
echo jdmonthname($jd, $mode), PHP_EOL;
