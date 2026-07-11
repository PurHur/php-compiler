--TEST--
stdlib proc_terminate(SIGKILL) + proc_close() returns signal 9 (#14684, ext/standard/proc_open.c)
--SKIPIF--
<?php
if (!function_exists('proc_open')) {
    die('skip proc_open unavailable');
}
--FILE--
<?php
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$proc = proc_open(['sleep', '60'], $descriptors, $pipes);
if (!is_resource($proc)) {
    echo "skip proc_open failed\n";
    exit(0);
}
foreach ($pipes as $pipe) {
    fclose($pipe);
}
proc_terminate($proc, 9);
echo 'closed=', proc_close($proc), "\n";
--EXPECT--
closed=9
