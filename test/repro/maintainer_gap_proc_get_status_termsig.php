<?php

declare(strict_types=1);

$desc = [1 => ['pipe', 'w']];
$proc = proc_open('echo ok', $desc, $pipes);
if (!\is_resource($proc)) {
    echo "fail: proc_open\n";
    exit(1);
}
$st = proc_get_status($proc);
$required = ['command', 'pid', 'running', 'signaled', 'stopped', 'exitcode', 'termsig', 'stopsig'];
foreach ($required as $key) {
    if (!\array_key_exists($key, $st)) {
        echo "fail: missing key $key\n";
        exit(1);
    }
}
if (!\is_int($st['termsig']) || !\is_int($st['stopsig'])) {
    echo "fail: termsig/stopsig not int\n";
    exit(1);
}
fclose($pipes[1]);
proc_close($proc);
echo "ok\n";
