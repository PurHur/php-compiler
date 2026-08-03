<?php

declare(strict_types=1);

/**
 * Repro #27387 — AOT jdtounix() must match Zend/VM.
 */
echo jdtounix(2461256), PHP_EOL;
$jd = 2461256;
echo jdtounix($jd), PHP_EOL;
