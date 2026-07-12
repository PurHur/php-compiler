--TEST--
stdlib proc_get_status() cached key absent on default 8.2 reference profile (#17883)
--FILE--
<?php
$desc = [1 => ['pipe', 'w']];
$pipes = [];
$proc = proc_open('echo ok', $desc, $pipes);
if (!is_resource($proc)) {
    echo "no-proc\n";
    exit(1);
}
$status = proc_get_status($proc);
echo array_key_exists('cached', $status) ? 'present' : 'absent', "\n";
fclose($pipes[1]);
proc_close($proc);
--EXPECT--
absent
