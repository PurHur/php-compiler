<?php

declare(strict_types=1);

$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open(['sleep', '1'], $descriptors, $pipes);
$st = proc_get_status(process: $proc);
$ok = is_array($st) && isset($st['pid']) && $st['pid'] > 0;
echo $ok ? "ok:{$st['pid']}\n" : "fail\n";
foreach ($pipes as $p) {
    fclose($p);
}
proc_close($proc);
exit($ok ? 0 : 1);
