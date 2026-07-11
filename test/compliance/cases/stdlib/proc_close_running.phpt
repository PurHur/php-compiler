--TEST--
stdlib proc_close() — returns -1 after proc_get_status reaped child (ext/standard/proc_open.c, #15661)
--FILE--
<?php
declare(strict_types=1);
$desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$pipes = [];
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
echo 'closed=', proc_close($proc), "\n";
--EXPECT--
stopped
exitcode=0
closed=-1
