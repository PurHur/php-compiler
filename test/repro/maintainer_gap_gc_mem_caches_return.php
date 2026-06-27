<?php
declare(strict_types=1);
$first = gc_mem_caches();
$second = gc_mem_caches();
echo 'first=', $first, "\n";
echo 'second=', $second, "\n";
if (57344 !== $first || 0 !== $second) {
    exit(1);
}
echo "ok\n";
