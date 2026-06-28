<?php

declare(strict_types=1);

// Issue #13020 — sys_getloadavg() must expose libc double precision, not /proc 2-decimal strings.
if (!is_readable('/proc/loadavg')) {
    fwrite(STDERR, "skip: /proc/loadavg unavailable\n");
    exit(0);
}

$raw = file_get_contents('/proc/loadavg');
if (!is_string($raw) || '' === $raw) {
    fwrite(STDERR, "fail: unreadable /proc/loadavg\n");
    exit(1);
}

$procField = explode(' ', trim($raw))[0] ?? '';
$avg = sys_getloadavg();
if (!is_array($avg) || 3 !== count($avg)) {
    fwrite(STDERR, "fail: expected 3-element array\n");
    exit(1);
}

$vmStr = rtrim(sprintf('%.12F', $avg[0]), '0');
if ($vmStr === $procField) {
    fwrite(STDERR, "fail: VM load matches /proc string only ($procField) — libc precision missing\n");
    exit(1);
}

echo "ok\n";
