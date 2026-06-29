--TEST--
stdlib proc_open() array argv + custom env — child sees override (ext/standard/proc_open.c, #13734)
--FILE--
<?php
$desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$pipes = [];
$proc = proc_open(['printenv', 'VAR'], $desc, $pipes, null, ['VAR' => 'expected']);
if (false === $proc) {
    echo "fail\n";
    exit(1);
}
$out = stream_get_contents($pipes[1]);
fclose($pipes[0]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($proc);
echo trim($out) === 'expected' ? "ok\n" : "fail\n";
--EXPECT--
ok
