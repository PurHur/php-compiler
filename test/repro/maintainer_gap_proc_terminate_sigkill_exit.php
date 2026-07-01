<?php
/**
 * Issue #14684 — proc_terminate(SIGKILL) + proc_close() must return signal 9, not 127 (php-src proc_open.c).
 */
declare(strict_types=1);

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$proc = proc_open(['sleep', '60'], $descriptors, $pipes);
if (!\is_resource($proc)) {
    fwrite(STDERR, "proc_open failed\n");
    exit(1);
}

foreach ($pipes as $pipe) {
    fclose($pipe);
}

if (!proc_terminate($proc, 9)) {
    fwrite(STDERR, "proc_terminate failed\n");
    exit(1);
}

$closed = proc_close($proc);
echo "closed={$closed}\n";
