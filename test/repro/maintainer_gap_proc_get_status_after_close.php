<?php

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

stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);
while ('' !== (string) stream_get_contents($pipes[1]) || '' !== (string) stream_get_contents($pipes[2])) {
}
fclose($pipes[1]);
fclose($pipes[2]);

$code = proc_close($proc);
$st = proc_get_status($proc);
$running = $st['running'] ?? null;

echo 'closed=', $code, "\n";
echo 'running=', var_export($running, true), "\n";

$ok = false === $running;
echo $ok ? "running_false\n" : "fail\n";
exit($ok ? 0 : 1);
