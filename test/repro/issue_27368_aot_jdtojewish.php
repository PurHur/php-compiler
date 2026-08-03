<?php

declare(strict_types=1);

/**
 * Repro #27368 — AOT jdtojewish() must match Zend/VM.
 */
echo jdtojewish(2460890), PHP_EOL;
$jd = 2460890;
echo jdtojewish($jd), PHP_EOL;
