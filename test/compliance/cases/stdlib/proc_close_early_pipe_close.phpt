--TEST--
stdlib proc_close() — exit code when pipes closed before child writes (ext/standard/proc_open.c, #14685)
--FILE--
<?php
declare(strict_types=1);
$desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$pipes = [];
$proc = proc_open('sleep 0.5; echo ok', $desc, $pipes);
if (!is_resource($proc)) {
    echo "no-proc\n";
    exit(1);
}
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);
fclose($pipes[1]);
fclose($pipes[2]);
$st = proc_get_status($proc);
echo ($st['running'] ? 'running' : 'stopped'), "\n";
echo 'closed=', proc_close($proc), "\n";
--EXPECT--
running
closed=1
