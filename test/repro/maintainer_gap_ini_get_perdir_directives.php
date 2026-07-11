<?php

declare(strict_types=1);

$expected = [
    'realpath_cache_size' => '4096K',
    'realpath_cache_ttl' => '120',
    'post_max_size' => '8M',
    'upload_max_filesize' => '2M',
];

foreach ($expected as $key => $want) {
    $got = ini_get($key);
    if ($got !== $want) {
        echo "fail: ini_get({$key}) ".var_export($got, true).' expected '.var_export($want, true)."\n";
        exit(1);
    }
}

echo "ok\n";
