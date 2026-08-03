<?php

declare(strict_types=1);

/**
 * Repro #27383 — AOT jdtofrench() must match Zend/VM.
 */
echo jdtofrench(2379867), PHP_EOL;
$jd = 2379867;
echo jdtofrench($jd), PHP_EOL;
