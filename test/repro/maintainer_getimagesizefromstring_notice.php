<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

$result = @getimagesizefromstring('not-image');
$last = error_get_last();
if (false !== $result) {
    echo "fail: expected false\n";
    exit(1);
}
if (8 !== ($last['type'] ?? null)) {
    echo 'fail: type='.var_export($last['type'] ?? null, true)."\n";
    exit(1);
}
$message = $last['message'] ?? '';
if (!str_contains($message, 'Error reading from not-image!')) {
    echo 'fail: message='.var_export($message, true)."\n";
    exit(1);
}

echo "ok\n";
