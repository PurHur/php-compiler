--TEST--
Stdlib: proc_open() child stdout readable after fclose(stdin) (VM, #15035)
--FILE--
<?php
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$pipes = null;
$proc = proc_open('echo hi', $descriptors, $pipes);
fclose($pipes[0]);
echo stream_get_contents($pipes[1]), "\n";
proc_close($proc);
--EXPECT--
hi
