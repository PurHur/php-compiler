<?php

declare(strict_types=1);

$desc = [
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$pipes = [];
$proc = proc_open('sleep 0', $desc, $pipes);
if (!is_resource($proc)) {
    echo "fail: no proc\n";
    exit(1);
}

fclose($pipes[1]);
fclose($pipes[2]);
usleep(100000);

$st = proc_get_status($proc);
$running = $st['running'] ?? null;
$exitcode = $st['exitcode'] ?? null;

echo 'running=', var_export($running, true), "\n";
echo 'exitcode=', var_export($exitcode, true), "\n";

$ok = false === $running && 0 === $exitcode;
echo $ok ? "ok\n" : "fail\n";

$code = proc_close($proc);
echo 'closed=', $code, "\n";
exit($ok ? 0 : 1);
