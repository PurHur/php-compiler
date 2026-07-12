<?php
/**
 * Repro #18419 — debug_zval_dump() stream resource line format.
 */
declare(strict_types=1);

$h = fopen('php://memory', 'r+');
debug_zval_dump($h);
fclose($h);
debug_zval_dump($h);
