--TEST--
stdlib proc_terminate() — signal running child (JIT, ext/standard/proc_open.c, #3740)
--FILE--
<?php
$desc = [1 => ['pipe', 'w']];
$pipes = [];
$proc = proc_open('sleep 60', $desc, $pipes);
if (!is_resource($proc)) {
    echo "no-proc\n";
    exit(1);
}
$st = proc_get_status($proc);
if (!$st['running']) {
    echo "not-running\n";
    exit(1);
}
echo proc_terminate($proc) ? 'terminated' : 'fail', "\n";
fclose($pipes[1]);
$code = proc_close($proc);
echo 'close:', $code, "\n";
--EXPECTF--
terminated
close:%d
