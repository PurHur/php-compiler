<?php
// Issue #10126 — proc_open() command:/descriptor_spec:/pipes: named parameters
$desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$pipes = [];
$proc = proc_open(command: 'echo ok', descriptor_spec: $desc, pipes: $pipes);
var_export(is_resource($proc));
echo "\n";
if (is_resource($proc)) {
    fclose($pipes[1]);
    proc_close($proc);
}
