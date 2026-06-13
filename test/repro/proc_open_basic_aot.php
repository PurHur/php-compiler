<?php
$desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$pipes = [];
$proc = proc_open('echo ok', $desc, $pipes);
if (!is_resource($proc)) {
    echo "no-proc\n";
    exit(1);
}
$out = stream_get_contents($pipes[1]);
fclose($pipes[1]);
$code = proc_close($proc);
echo trim($out), ':', $code, "\n";
