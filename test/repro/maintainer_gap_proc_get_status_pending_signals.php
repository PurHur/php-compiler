<?php

declare(strict_types=1);

/**
 * Repro #28527 — pending_signals must stay absent (never in php-src Zend 8.3–8.5).
 * Run with PHP_COMPILER_PROFILE=8.4.
 */

$desc = [1 => ['pipe', 'w']];
$pipes = [];
$proc = proc_open('echo ok', $desc, $pipes);
if (!\is_resource($proc)) {
    echo "fail: no proc\n";
    exit(1);
}

$status = proc_get_status($proc);
$hasKey = \array_key_exists('pending_signals', $status);

echo 'has_key=', \var_export($hasKey, true), "\n";

fclose($pipes[1]);
proc_close($proc);

$ok = !$hasKey;
echo $ok ? "ok\n" : "fail\n";
exit($ok ? 0 : 1);
