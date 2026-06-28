--TEST--
stdlib proc_get_status() — status array key insertion order (ext/standard/exec.c, #13210)
--FILE--
<?php
$expected = ['command', 'pid', 'running', 'signaled', 'stopped', 'exitcode', 'termsig', 'stopsig'];
$desc = [1 => ['pipe', 'w']];
$pipes = [];
$proc = proc_open('echo ok', $desc, $pipes);
if (!is_resource($proc)) {
    echo "no-proc\n";
    exit(1);
}
$keys = array_keys(proc_get_status($proc));
echo $keys === $expected ? 'order-ok' : 'order-bad', "\n";
fclose($pipes[1]);
proc_close($proc);
--EXPECT--
order-ok
