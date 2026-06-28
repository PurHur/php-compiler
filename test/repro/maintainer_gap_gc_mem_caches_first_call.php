<?php
declare(strict_types=1);

$first = gc_mem_caches();
$second = gc_mem_caches();
if (0 !== $second) {
    echo "fail: second call expected 0, got $second\n";
    exit(1);
}
$expected = (int) trim((string) shell_exec((defined('PHP_BINARY') ? PHP_BINARY : 'php') . ' -n -r ' . escapeshellarg('echo gc_mem_caches();')));
if ($first !== $expected) {
    echo "fail: first=$first expected=$expected (host Zend MM bucket)\n";
    exit(1);
}
echo "ok\n";
