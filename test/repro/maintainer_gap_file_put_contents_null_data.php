<?php

declare(strict_types=1);

// Maintainer gap #17024 — file_put_contents(null) coerces to empty string (ext/standard/file.c Z_PARAM_STR).
$path = sys_get_temp_dir().'/phpc_fpc_null_'.getmypid().'.txt';
@unlink($path);

error_reporting(E_ALL & ~E_DEPRECATED);
$n = file_put_contents($path, null);
if (0 !== $n) {
    fwrite(STDERR, 'fail: expected int(0), got '.var_export($n, true)."\n");
    exit(1);
}
$contents = file_get_contents($path);
if ('' !== $contents) {
    fwrite(STDERR, 'fail: expected empty file, got '.var_export($contents, true)."\n");
    exit(1);
}
@unlink($path);
echo "ok\n";
