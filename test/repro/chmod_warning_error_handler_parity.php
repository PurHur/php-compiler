<?php

declare(strict_types=1);

$path = sys_get_temp_dir().'/phpc_chmod_missing_'.getmypid().'_nope';

$handled = false;
set_error_handler(static function (int $errno, string $message) use (&$handled): bool {
    if (str_contains($message, 'chmod(): No such file or directory')) {
        $handled = true;
        echo 'HANDLER:'.$message."\n";
    }

    return true;
});

$result = chmod($path, 0644);
restore_error_handler();

if (false !== $result) {
    echo "fail: expected false from chmod failure\n";
    exit(1);
}
if (!$handled) {
    echo "fail: user error handler never ran\n";
    exit(1);
}
echo "ok\n";
