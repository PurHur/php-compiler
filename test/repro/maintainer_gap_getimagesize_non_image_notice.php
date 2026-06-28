<?php

declare(strict_types=1);

$path = sys_get_temp_dir().'/phpc_getimagesize_non_image_'.getmypid().'.txt';
file_put_contents($path, 'not an image');

$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $severity.': '.$message;

    return true;
});

$result = getimagesize($path);
@unlink($path);

if (false !== $result) {
    echo 'fail: getimagesize() returned ', var_export($result, true), "\n";
    exit(1);
}

if ([] !== $warnings) {
    echo 'fail: unexpected warnings: ', implode('; ', $warnings), "\n";
    exit(1);
}

echo "ok\n";
