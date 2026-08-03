<?php

declare(strict_types=1);

/**
 * Repro #27382 — AOT frenchtojd() must match Zend/VM.
 */
echo frenchtojd(1, 1, 1), PHP_EOL;
$m = 1;
$d = 1;
$y = 1;
echo frenchtojd($m, $d, $y), PHP_EOL;
