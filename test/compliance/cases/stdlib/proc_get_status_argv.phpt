--TEST--
stdlib proc_get_status() — argv-array proc_open command path (ext/standard/proc_open.c, #9311)
--FILE--
<?php
$desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open(['/bin/echo', 'hi'], $desc, $pipes);
if (!is_resource($proc)) {
    echo "no-proc\n";
    exit(1);
}
$st = proc_get_status($proc);
echo ($st['command'] === '/bin/echo' ? 'cmd-ok' : 'cmd-bad'), "\n";
echo ($st['running'] ? 'running' : 'stopped'), "\n";
echo (is_int($st['pid']) && $st['pid'] > 0 ? 'has-pid' : 'no-pid'), "\n";
proc_close($proc);
--EXPECT--
cmd-ok
running
has-pid
