--TEST--
stdlib proc_get_status() — process: named parameter (ext/standard/exec.c, #16625)
--FILE--
<?php
$desc = [1 => ['pipe', 'w']];
$pipes = [];
$proc = proc_open('echo ok', $desc, $pipes);
if (!is_resource($proc)) {
    echo "no-proc\n";
    exit(1);
}
$st = proc_get_status(process: $proc);
echo ($st['running'] ? 'running' : 'stopped'), "\n";
echo (is_int($st['pid']) && $st['pid'] > 0 ? 'has-pid' : 'no-pid'), "\n";
fclose($pipes[1]);
proc_close($proc);
echo "ok\n";
--EXPECT--
running
has-pid
ok
