<?php

declare(strict_types=1);

$desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$pipes = [];
$proc = proc_open(['printenv', 'VAR'], $desc, $pipes, null, ['VAR' => 'expected']);
if (false === $proc) {
    echo "fail open\n";
    exit(1);
}
$out = stream_get_contents($pipes[1]);
fclose($pipes[0]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($proc);
echo trim($out) === 'expected' ? "ok\n" : 'got: ' . $out . "\n";
