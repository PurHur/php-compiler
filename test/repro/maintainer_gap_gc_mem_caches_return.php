<?php
declare(strict_types=1);
$first = gc_mem_caches();
$second = gc_mem_caches();
echo 'first=', $first, "\n";
echo 'second=', $second, "\n";
$expected = (int) trim((string) shell_exec((defined('PHP_BINARY') ? PHP_BINARY : 'php') . ' -n -r ' . escapeshellarg('echo gc_mem_caches();')));
if ($first !== $expected || 0 !== $second) {
    exit(1);
}
echo "ok\n";
