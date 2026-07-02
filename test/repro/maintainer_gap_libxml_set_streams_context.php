<?php

declare(strict_types=1);

if (!function_exists('libxml_set_streams_context')) {
    echo "fail: libxml_set_streams_context() undefined\n";
    exit(1);
}

$ctx = stream_context_create(['http' => ['timeout' => 1.5]]);
libxml_set_streams_context($ctx);

try {
    libxml_set_streams_context(null);
    echo "fail: expected TypeError for null context\n";
    exit(1);
} catch (\TypeError $e) {
    if (!str_contains($e->getMessage(), 'must be of type resource')) {
        echo 'fail: wrong TypeError: ', $e->getMessage(), "\n";
        exit(1);
    }
}

echo "ok\n";
