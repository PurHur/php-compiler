<?php
declare(strict_types=1);
// Issue #25925 — proc_close after early pipe close returns child exit (re-#14685).
$desc = [
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$pipes = [];
$proc = proc_open('sleep 0.3; exit 1', $desc, $pipes);
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);
@stream_get_contents($pipes[1]);
@stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$st = proc_get_status($proc);
echo ($st['running'] ? 'running' : 'stopped'), "\n";
echo 'closed=', proc_close($proc), "\n";
