--TEST--
stdlib proc_get_status() after proc_close() — TypeError (ext/standard/proc_open.c, #16967)
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
try {
    proc_get_status($proc);
    echo "no-throw\n";
} catch (TypeError $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
echo 'closed=', $code, "\n";
--EXPECT--
TypeError
proc_get_status(): supplied resource is not a valid process resource
closed=0
