<?php

declare(strict_types=1);

/**
 * Maintainer repro: proc_get_status() cached key on forward profile (#17362, #28527).
 *
 * php-src: ext/standard/proc_open.c — proc_get_status() "cached" after pid (PHP 8.3+)
 * Run with: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_proc_get_status_cached_key.php
 */

$desc = [
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$pipes = [];
$proc = proc_open('true', $desc, $pipes);
if (!is_resource($proc)) {
    echo "fail: no proc\n";
    exit(1);
}
fclose($pipes[1]);
fclose($pipes[2]);

for ($i = 0; $i < 50; ++$i) {
    $status = proc_get_status($proc);
    if (!$status['running']) {
        break;
    }
    usleep(10000);
}

$hasCached = array_key_exists('cached', $status);
$hasPending = array_key_exists('pending_signals', $status);
echo 'has_cached=', $hasCached ? '1' : '0', "\n";
if ($hasCached) {
    echo 'cached=', $status['cached'] ? '1' : '0', "\n";
}
echo 'has_pending=', $hasPending ? '1' : '0', "\n";
echo 'keys=', implode(',', array_keys($status)), "\n";
echo 'running=', $status['running'] ? '1' : '0', "\n";
proc_close($proc);

$ok = $hasCached && !$hasPending
    && array_keys($status) === ['command', 'pid', 'cached', 'running', 'signaled', 'stopped', 'exitcode', 'termsig', 'stopsig'];
echo $ok ? "ok\n" : "fail\n";
exit($ok ? 0 : 1);
