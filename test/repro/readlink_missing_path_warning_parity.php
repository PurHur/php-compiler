<?php

declare(strict_types=1);

$missing = sys_get_temp_dir().'/phpc_readlink_missing_'.getmypid().'_nope';

$warned = false;
set_error_handler(static function (int $errno, string $message) use (&$warned): bool {
    if (str_contains($message, 'readlink(): No such file or directory')) {
        $warned = true;
    }

    return true;
});

$result = readlink($missing);
restore_error_handler();

if (false !== $result) {
    echo "fail: expected false\n";
    exit(1);
}
if (!$warned) {
    echo "fail: missing No such file or directory warning\n";
    exit(1);
}
echo "ok\n";
