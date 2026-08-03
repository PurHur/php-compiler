<?php

declare(strict_types=1);

/**
 * Repro #27367 — AOT unixtojd() must match Zend/VM.
 */
echo unixtojd(1754236800), PHP_EOL;
$ts = 1754236800;
echo unixtojd($ts), PHP_EOL;
