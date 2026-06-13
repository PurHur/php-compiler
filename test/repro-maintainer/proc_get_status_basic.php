<?php
$desc = [1 => ['pipe', 'w']];
$pipes = [];
$proc = proc_open('echo ok', $desc, $pipes);
$st = proc_get_status($proc);
echo ($st['running'] ? 'running' : 'stopped'), "\n";
fclose($pipes[1]);
proc_close($proc);
