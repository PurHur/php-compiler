--TEST--
stdlib proc_get_status() after proc_close() — running=false on closed handle (ext/standard/proc_open.c, #16863)
--FILE--
<?php
$desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open('true', $desc, $pipes);
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
$code = proc_close($proc);
$st = proc_get_status($proc);
echo 'closed=', $code, "\n";
echo ($st['running'] ? 'running' : 'stopped'), "\n";
--EXPECT--
closed=0
stopped
