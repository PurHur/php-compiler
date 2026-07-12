<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

@utf8_encode('café');
$last = error_get_last();
if (8192 !== ($last['type'] ?? null)) {
    echo 'fail utf8_encode type='.var_export($last['type'] ?? null, true)."\n";
    exit(1);
}
if (!str_contains($last['message'] ?? '', 'Function utf8_encode() is deprecated')) {
    echo 'fail utf8_encode message='.var_export($last['message'] ?? '', true)."\n";
    exit(1);
}

@utf8_decode('caf%');
$last = error_get_last();
if (8192 !== ($last['type'] ?? null)) {
    echo 'fail utf8_decode type='.var_export($last['type'] ?? null, true)."\n";
    exit(1);
}
if (!str_contains($last['message'] ?? '', 'Function utf8_decode() is deprecated')) {
    echo 'fail utf8_decode message='.var_export($last['message'] ?? '', true)."\n";
    exit(1);
}

echo "ok\n";
