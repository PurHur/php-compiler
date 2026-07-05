--TEST--
stdlib proc_get_status() — process: named parameter (#16625, ext/standard/exec.c)
--FILE--
<?php
$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open(['sleep', '1'], $descriptors, $pipes);
$st = proc_get_status(process: $proc);
echo is_array($st) && isset($st['pid']) && $st['pid'] > 0 ? "ok\n" : "fail\n";
foreach ($pipes as $p) {
    fclose($p);
}
proc_close($proc);
--EXPECT--
ok
