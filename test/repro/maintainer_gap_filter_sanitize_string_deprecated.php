<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

@filter_var('test', FILTER_SANITIZE_STRING);
$last = error_get_last();
if (8192 !== ($last['type'] ?? null)) {
    echo 'fail type='.var_export($last['type'] ?? null, true)."\n";
    exit(1);
}
if (!str_contains($last['message'] ?? '', 'Constant FILTER_SANITIZE_STRING is deprecated')) {
    echo 'fail message='.var_export($last['message'] ?? '', true)."\n";
    exit(1);
}

echo "ok\n";
