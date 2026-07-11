--TEST--
stdlib proc_get_status() — running=true after pipe drain before proc_close (ext/standard/proc_open.c, #13079)
--FILE--
<?php
$desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open('echo ok', $desc, $pipes);
if (!is_resource($proc)) {
    echo "no-proc\n";
    exit(1);
}
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);
while ('' !== (string) stream_get_contents($pipes[1]) || '' !== (string) stream_get_contents($pipes[2])) {
}
fclose($pipes[1]);
fclose($pipes[2]);
$st = proc_get_status($proc);
echo ($st['running'] ? 'running' : 'stopped'), "\n";
echo ($st['exitcode'] === -1 ? 'exitcode-pending' : 'exitcode-known'), "\n";
proc_close($proc);
--EXPECT--
running
exitcode-pending
