<?php

declare(strict_types=1);

$dir = sys_get_temp_dir().'/phpc_closedir_'.getmypid();
mkdir($dir);
$dh = opendir($dir);
if (false === $dh) {
    echo "fail: opendir\n";
    exit(1);
}
closedir($dh);

try {
    closedir($dh);
    echo "fail: expected TypeError\n";
    exit(1);
} catch (\TypeError $e) {
    $msg = $e->getMessage();
    if (!str_contains($msg, 'not a valid directory resource')) {
        echo "fail: wrong message: {$msg}\n";
        exit(1);
    }
}

// Non-resource must still get generic resource TypeError.
try {
    closedir(42);
    echo "fail: expected TypeError for int\n";
    exit(1);
} catch (\TypeError $e) {
    $msg = $e->getMessage();
    if (!str_contains($msg, 'must be of type resource')) {
        echo "fail: wrong int message: {$msg}\n";
        exit(1);
    }
}

rmdir($dir);
echo "ok\n";
