--TEST--
stdlib proc_get_status() — short-lived child reports exit after pipe close + brief wait (ext/standard/proc_open.c, #15647)
--FILE--
<?php
$desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open('sleep 0', $desc, $pipes);
if (!is_resource($proc)) {
    echo "no-proc\n";
    exit(1);
}
fclose($pipes[1]);
fclose($pipes[2]);
usleep(100000);
$st = proc_get_status($proc);
echo ($st['running'] ? 'running' : 'stopped'), "\n";
echo 'exitcode=', $st['exitcode'], "\n";
proc_close($proc);
--EXPECT--
stopped
exitcode=0
