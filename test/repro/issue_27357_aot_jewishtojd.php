<?php

declare(strict_types=1);

/**
 * Repro #27357 — AOT jewishtojd() must match Zend/VM.
 */
echo jewishtojd(1, 1, 5784), PHP_EOL;
$m = 1;
$d = 1;
$y = 5784;
echo jewishtojd($m, $d, $y), PHP_EOL;
