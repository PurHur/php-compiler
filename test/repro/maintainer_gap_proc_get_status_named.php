<?php
declare(strict_types=1);

$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open(['sleep', '1'], $descriptors, $pipes);
if (!\is_resource($proc)) {
    fwrite(STDERR, "fail: proc_open\n");
    exit(1);
}

$st = proc_get_status(process: $proc);
if (!isset($st['pid']) || !\is_int($st['pid']) || $st['pid'] <= 0) {
    fwrite(STDERR, 'fail: missing pid ' . var_export($st['pid'] ?? null, true) . "\n");
    exit(1);
}

echo "ok:{$st['pid']}\n";

foreach ($pipes as $p) {
    fclose($p);
}
proc_close($proc);
