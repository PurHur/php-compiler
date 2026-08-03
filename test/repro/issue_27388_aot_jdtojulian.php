<?php

declare(strict_types=1);

/**
 * Repro #27388 — AOT jdtojulian() must match Zend/VM.
 */
echo jdtojulian(2461256), PHP_EOL;
$jd = 2461256;
echo jdtojulian($jd), PHP_EOL;
