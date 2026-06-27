<?php

declare(strict_types=1);

$usage = getrusage();
if (!\is_array($usage) || !isset($usage['ru_maxrss'])) {
    fwrite(STDERR, "MISSING: getrusage ru_maxrss\n");
    exit(1);
}
if ((int) $usage['ru_maxrss'] <= 0) {
    fwrite(STDERR, 'FAIL: ru_maxrss='.(string) $usage['ru_maxrss']."\n");
    exit(1);
}
echo "ok\n";
