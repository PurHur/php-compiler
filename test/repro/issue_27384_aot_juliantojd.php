<?php

declare(strict_types=1);

/**
 * Repro #27384 — AOT juliantojd() must match Zend/VM.
 */
echo juliantojd(1, 1, 2000), PHP_EOL;
$m = 1;
$d = 1;
$y = 2000;
echo juliantojd($m, $d, $y), PHP_EOL;
