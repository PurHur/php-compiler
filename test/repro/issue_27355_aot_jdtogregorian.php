<?php

declare(strict_types=1);

/**
 * Repro #27355 — AOT jdtogregorian() must match Zend/VM.
 */
echo jdtogregorian(2460310), PHP_EOL;
$jd = 2460310;
echo jdtogregorian($jd), PHP_EOL;
