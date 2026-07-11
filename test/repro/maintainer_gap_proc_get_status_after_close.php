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
try {
    proc_get_status($proc);
    echo "fail: expected TypeError after proc_close\n";
    exit(1);
} catch (TypeError $e) {
    echo get_class($e), "\n";
}

echo 'closed=', $code, "\n";
echo "ok\n";
