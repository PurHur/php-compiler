<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

@strftime('%Y-%m-%d', time());
$last = error_get_last();
if (8192 !== ($last['type'] ?? null)) {
    echo 'fail strftime type='.var_export($last['type'] ?? null, true)."\n";
    exit(1);
}
if (!str_contains($last['message'] ?? '', 'Function strftime() is deprecated')) {
    echo 'fail strftime message='.var_export($last['message'] ?? '', true)."\n";
    exit(1);
}

@gmstrftime('%Y-%m-%d', time());
$last = error_get_last();
if (8192 !== ($last['type'] ?? null)) {
    echo 'fail gmstrftime type='.var_export($last['type'] ?? null, true)."\n";
    exit(1);
}
if (!str_contains($last['message'] ?? '', 'Function gmstrftime() is deprecated')) {
    echo 'fail gmstrftime message='.var_export($last['message'] ?? '', true)."\n";
    exit(1);
}

echo "ok\n";
