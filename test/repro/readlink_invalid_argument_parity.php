<?php

declare(strict_types=1);

$path = sys_get_temp_dir().'/phpc_readlink_file_'.getmypid().'.txt';
file_put_contents($path, 'x');

$warned = false;
set_error_handler(static function (int $errno, string $message) use (&$warned): bool {
    if (str_contains($message, 'readlink(): Invalid argument')) {
        $warned = true;
    }

    return true;
});

$result = readlink($path);
restore_error_handler();
unlink($path);

if (false !== $result) {
    echo "fail: expected false\n";
    exit(1);
}
if (!$warned) {
    echo "fail: missing Invalid argument warning\n";
    exit(1);
}
echo "ok\n";
