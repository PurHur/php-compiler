--TEST--
stdlib proc_open() — child stdout readable via stream_get_contents (ext/standard/proc_open.c, #15035)
--FILE--
<?php
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$pipes = null;
$proc = proc_open('echo hi', $descriptors, $pipes);
if (!is_resource($proc)) {
    echo "no-proc\n";
    exit(1);
}
fclose($pipes[0]);
echo stream_get_contents($pipes[1]), "\n";
proc_close($proc);
--EXPECT--
hi
