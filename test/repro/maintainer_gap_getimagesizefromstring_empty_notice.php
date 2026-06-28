<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

$result = @getimagesizefromstring('');
if (false !== $result) {
    echo "fail: expected false\n";
    exit(1);
}

$last = error_get_last();
if (null === $last || 8 !== $last['type']) {
    echo 'fail: expected E_NOTICE in error_get_last(), got '.var_export($last, true)."\n";
    exit(1);
}
if (!str_contains($last['message'], 'Error reading from !')) {
    echo 'fail: unexpected notice: '.$last['message']."\n";
    exit(1);
}

echo "ok\n";
