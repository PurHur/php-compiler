<?php

declare(strict_types=1);

$desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$pipes = [];
$proc = proc_open('sleep 2; echo late', $desc, $pipes);
if (!is_resource($proc)) {
    fwrite(STDERR, "proc_open() failed\n");
    exit(1);
}
$read = [$pipes[1]];
$write = null;
$except = null;
$n = stream_select($read, $write, $except, 0, 100000);
if (!\is_int($n)) {
    fwrite(STDERR, 'expected int from stream_select(), got ');
    var_export($n);
    fwrite(STDERR, "\n");
    exit(1);
}
echo "ready=$n\n";
proc_close($proc);
