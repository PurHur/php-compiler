<?php

declare(strict_types=1);

$mem = fopen('php://memory', 'r+');
if (!stream_set_blocking($mem, false)) {
    echo "memory set_blocking failed\n";
    exit(1);
}
$memMeta = stream_get_meta_data($mem);
if (($memMeta['blocked'] ?? null) !== true) {
    echo 'memory blocked=', var_export($memMeta['blocked'] ?? null, true), "\n";
    exit(1);
}
fclose($mem);

$temp = fopen('php://temp', 'r+');
if (!stream_set_blocking($temp, false)) {
    echo "temp set_blocking failed\n";
    exit(1);
}
$tempMeta = stream_get_meta_data($temp);
if (array_key_exists('blocked', $tempMeta)) {
    echo 'temp blocked present=', var_export($tempMeta['blocked'], true), "\n";
    exit(1);
}
fclose($temp);

echo "ok\n";
