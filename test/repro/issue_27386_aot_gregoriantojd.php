<?php

declare(strict_types=1);

/**
 * Repro #27386 — AOT gregoriantojd() must match Zend/VM.
 */
echo gregoriantojd(8, 3, 2026), PHP_EOL;
$m = 8;
$d = 3;
$y = 2026;
echo gregoriantojd($m, $d, $y), PHP_EOL;
