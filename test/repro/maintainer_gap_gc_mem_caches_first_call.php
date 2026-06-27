<?php
declare(strict_types=1);

$first = gc_mem_caches();
$second = gc_mem_caches();
if (0 !== $second) {
    echo "fail: second call expected 0, got $second\n";
    exit(1);
}
if (61440 !== $first) {
    echo "fail: first=$first expected 61440 (Zend MM 15-page bucket)\n";
    exit(1);
}
echo "ok\n";
