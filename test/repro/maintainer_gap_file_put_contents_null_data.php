<?php

declare(strict_types=1);

// Maintainer gap #17024 — file_put_contents(null data) coerces to empty string (ext/standard/file.c).
$path = tempnam(sys_get_temp_dir(), 'fpc_null_');
if (!\is_string($path)) {
    fwrite(STDERR, "fail: tempnam\n");
    exit(1);
}
$written = file_put_contents($path, null);
if (0 !== $written) {
    fwrite(STDERR, "fail: expected 0 bytes written, got {$written}\n");
    @unlink($path);
    exit(1);
}
if (0 !== filesize($path)) {
    fwrite(STDERR, "fail: expected empty file\n");
    @unlink($path);
    exit(1);
}
@unlink($path);
echo "ok\n";
