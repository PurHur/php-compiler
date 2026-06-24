--TEST--
stdlib proc_open() array command — native execvp, no host proc_open (#8889, ext/standard/proc_open.c)
--FILE--
<?php
$desc = [1 => ['pipe', 'w']];
$pipes = [];
$proc = proc_open(['echo', 'ok'], $desc, $pipes);
if (!is_resource($proc)) {
    echo "fail\n";
    exit(1);
}
$out = stream_get_contents($pipes[1]);
fclose($pipes[1]);
proc_close($proc);
echo trim($out) === 'ok' ? "ok\n" : "fail\n";
--EXPECT--
ok
