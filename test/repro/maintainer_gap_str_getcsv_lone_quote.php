<?php

declare(strict_types=1);

/**
 * Issue #18592 — str_getcsv('"') lone quote must yield NUL byte field (php-src ext/standard/file.c).
 *
 * Zend 8.2: count=1 len=1 ord0=0
 */
$row = str_getcsv('"');
$count = count($row);
$len = strlen($row[0]);
$ord0 = ord($row[0]);

echo "count=$count len=$len ord0=$ord0\n";

if (1 !== $count) {
    fwrite(STDERR, "fail: expected count=1, got $count\n");
    exit(1);
}
if (1 !== $len) {
    fwrite(STDERR, "fail: expected len=1, got $len\n");
    exit(1);
}
if (0 !== $ord0) {
    fwrite(STDERR, "fail: expected ord0=0, got $ord0\n");
    exit(1);
}

echo "ok\n";
