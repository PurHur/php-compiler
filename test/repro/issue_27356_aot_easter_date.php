<?php

declare(strict_types=1);

/**
 * Repro #27356 — AOT easter_date() must match Zend/VM.
 */
echo easter_date(2024), PHP_EOL;
$year = 2024;
echo easter_date($year), PHP_EOL;
