<?php
declare(strict_types=1);
$path = sys_get_temp_dir().'/phpc_issue_31338_'.getmypid().'.txt';
file_put_contents($path, 'x');
try {
    $r = file_get_contents($path, null);
    echo "OK len=", strlen((string) $r), "\n";
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
@unlink($path);
