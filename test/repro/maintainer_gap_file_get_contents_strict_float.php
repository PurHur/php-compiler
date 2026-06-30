<?php

declare(strict_types=1);

$path = sys_get_temp_dir().'/phpc_fgc_strict_float_'.getmypid().'.txt';
file_put_contents($path, 'abcdef');

try {
    file_get_contents($path, false, null, 1.9, 2.7);
    fwrite(STDERR, "fail: expected TypeError for float offset/length under strict_types\n");
    @unlink($path);
    exit(1);
} catch (TypeError $e) {
    if (!str_contains($e->getMessage(), 'file_get_contents(): Argument #4 ($offset) must be of type int, float given')
        && !str_contains($e->getMessage(), 'file_get_contents(): Argument #5 ($length) must be of type int, float given')) {
        fwrite(STDERR, 'unexpected message: '.$e->getMessage()."\n");
        @unlink($path);
        exit(1);
    }
}

@unlink($path);
echo "ok\n";
