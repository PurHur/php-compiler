<?php
/**
 * Maintainer repro: proc_get_status() cached key on forward profile (#17362).
 *
 * php-src: ext/standard/proc_open.c — proc_get_status() "cached" entry (PHP 8.3+)
 */
declare(strict_types=1);

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
echo 'has_cached=', $hasCached ? '1' : '0', "\n";
if ($hasCached) {
    echo 'cached=', $status['cached'] ? '1' : '0', "\n";
}
echo 'running=', $status['running'] ? '1' : '0', "\n";
proc_close($proc);
