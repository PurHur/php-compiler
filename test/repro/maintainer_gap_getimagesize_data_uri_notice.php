<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

$uri = 'data://text/plain,not';
$result = @getimagesize($uri);
if (false !== $result) {
    echo "fail: expected false\n";
    exit(1);
}

$last = error_get_last();
if (null === $last || 8 !== $last['type']) {
    echo 'fail: expected E_NOTICE, got type '.var_export($last['type'] ?? null, true)."\n";
    exit(1);
}
if (!str_contains($last['message'], 'Error reading from data://text/plain,not!')) {
    echo 'fail: unexpected notice: '.$last['message']."\n";
    exit(1);
}

echo "ok\n";
