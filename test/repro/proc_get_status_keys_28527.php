<?php

declare(strict_types=1);

/**
 * Issue #28527 repro — proc_get_status() key set vs Zend 8.4+.
 * Expect: ... pid,cached,running ... ; cached=y pending=n
 */

$d = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$p = proc_open(PHP_BINARY.' -r "usleep(200000);"', $d, $pipes);
usleep(30000);
$s = proc_get_status($p);
echo implode(',', array_keys($s)), "\n";
echo array_key_exists('cached', $s) ? 'cached=y' : 'cached=n', ' ';
echo array_key_exists('pending_signals', $s) ? 'pending=y' : 'pending=n', "\n";
@proc_terminate($p);
@proc_close($p);
