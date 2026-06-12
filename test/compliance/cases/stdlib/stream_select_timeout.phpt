--TEST--
stdlib stream_select() — timeout before slow child output (ext/standard/streams.c, #3131)
--FILE--
<?php
$desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$pipes = [];
$proc = proc_open('sleep 2; echo late', $desc, $pipes);
if (!is_resource($proc)) {
    echo "no-proc\n";
    exit(1);
}
$read = [$pipes[1]];
$write = $except = null;
$n = stream_select($read, $write, $except, 0, 100000);
echo "ready=$n\n";
proc_close($proc);
--EXPECT--
ready=0
