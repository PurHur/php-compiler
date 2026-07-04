<?php

declare(strict_types=1);

$desc = [1 => ['pipe', 'w']];
$pipes = [];
$proc = proc_open(['echo', 'hi'], $desc, $pipes);
if (!is_resource($proc)) {
    echo "fail open\n";
    exit(1);
}
$out = stream_get_contents($pipes[1]);
fclose($pipes[1]);
proc_close($proc);
echo trim($out) === 'hi' ? "ok\n" : 'got: ' . $out . "\n";
