<?php

declare(strict_types=1);

$desc = [
    0 => ['pipe', 'r'],
    2 => ['pipe', 'w'],
];
$pipes = [];
$proc = proc_open('true', $desc, $pipes);
if (!is_resource($proc)) {
    echo "fail: no proc\n";
    exit(1);
}

try {
    stream_set_blocking($pipes[1], false);
    echo "fail: expected TypeError for missing pipe slot\n";
    exit(1);
} catch (TypeError $e) {
    $msg = $e->getMessage();
    if (false === str_contains($msg, 'Argument #1')) {
        echo 'fail: wrong message: ', $msg, "\n";
        exit(1);
    }
    if (false === str_contains($msg, 'resource')) {
        echo 'fail: expected resource type error: ', $msg, "\n";
        exit(1);
    }
    echo "ok: ", $msg, "\n";
    exit(0);
}
