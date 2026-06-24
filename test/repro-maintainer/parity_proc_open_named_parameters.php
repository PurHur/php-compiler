<?php
declare(strict_types=1);

// Issue #11078 — proc_open() array command + cwd: named parameters (ext/standard/exec.stub.php).

$desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$pipes = [];
$proc = proc_open(
    command: ['echo', 'hi'],
    descriptor_spec: $desc,
    pipes: $pipes,
    cwd: null,
);
if (!is_resource($proc)) {
    echo "FAIL\n";
    exit(1);
}
$out = stream_get_contents($pipes[1]);
fclose($pipes[1]);
proc_close($proc);
echo trim($out) === 'hi' ? "OK\n" : "FAIL\n";
