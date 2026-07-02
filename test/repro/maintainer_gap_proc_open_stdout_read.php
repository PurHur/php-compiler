<?php
// Repro for #15035 — proc_open stdout readable via stream_get_contents (AOT/VM).
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$pipes = null;
$proc = proc_open('echo hi', $descriptors, $pipes);
if (!is_resource($proc)) {
    fwrite(STDERR, "proc_open failed\n");
    exit(1);
}
fclose($pipes[0]);
$out = stream_get_contents($pipes[1]);
proc_close($proc);
echo $out === "hi\n" ? "proc_open_stdout_ok\n" : "proc_open_stdout_fail\n";
