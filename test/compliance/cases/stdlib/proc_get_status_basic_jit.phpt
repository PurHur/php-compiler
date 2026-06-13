--TEST--
stdlib proc_get_status() — running child metadata (JIT, ext/standard/proc_open.c, #3740)
--FILE--
<?php
$desc = [1 => ['pipe', 'w']];
$pipes = [];
$proc = proc_open('echo ok', $desc, $pipes);
if (!is_resource($proc)) {
    echo "no-proc\n";
    exit(1);
}
$st = proc_get_status($proc);
echo ($st['running'] ? 'running' : 'stopped'), "\n";
echo (is_int($st['pid']) && $st['pid'] > 0 ? 'has-pid' : 'no-pid'), "\n";
echo ($st['command'] === 'echo ok' ? 'cmd-ok' : 'cmd-bad'), "\n";
fclose($pipes[1]);
echo proc_close($proc), "\n";
--EXPECT--
running
has-pid
cmd-ok
0
