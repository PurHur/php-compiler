<?php
declare(strict_types=1);

$first = gc_mem_caches();
$second = gc_mem_caches();
if (0 !== $second) {
    echo "fail: second call expected 0, got $second\n";
    exit(1);
}
if (61440 === $first) {
    echo "fail: stale 15-page MM cache bucket (61440)\n";
    exit(1);
}
if ($first <= 0 || 0 !== $first % 4096) {
    echo "fail: first=$first expected positive page multiple\n";
    exit(1);
}
echo "ok\n";
