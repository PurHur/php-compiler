<?php

declare(strict_types=1);

// Issue #13018 — getrusage(RUSAGE_CHILDREN) must be zeroed when no children waited.
$usage = getrusage(1);
if (!is_array($usage)) {
    fwrite(STDERR, "fail: expected array\n");
    exit(1);
}
if (0 !== ($usage['ru_maxrss'] ?? -1)) {
    fwrite(STDERR, 'fail: ru_maxrss='.var_export($usage['ru_maxrss'], true)."\n");
    exit(1);
}

echo "ok\n";
