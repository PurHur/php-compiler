<?php

declare(strict_types=1);

$desc = [
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$pipes = [];
$proc = proc_open('echo ok', $desc, $pipes);
if (!is_resource($proc)) {
    echo "fail: no proc\n";
    exit(1);
}

// Drain stdout/stderr without proc_close (php-src keeps running=true until reap).
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);
while ('' !== ($chunk = (string) stream_get_contents($pipes[1])) || '' !== ($chunk2 = (string) stream_get_contents($pipes[2]))) {
    // spin until drained
    if ('' === $chunk && '' === $chunk2) {
        break;
    }
}
fclose($pipes[1]);
fclose($pipes[2]);

$st = proc_get_status($proc);
$running = $st['running'] ?? null;
$exitcode = $st['exitcode'] ?? null;

echo 'running=', var_export($running, true), "\n";
echo 'exitcode=', var_export($exitcode, true), "\n";

$ok = true === $running && -1 === $exitcode;
echo $ok ? "ok\n" : "fail\n";

$code = proc_close($proc);
echo 'closed=', $code, "\n";
exit($ok ? 0 : 1);
