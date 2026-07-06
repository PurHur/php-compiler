<?php

declare(strict_types=1);

$fp = fopen('php://memory', 'r+');
stream_filter_append($fp, 'string.toupper', STREAM_FILTER_READ);

try {
    stream_filter_remove($fp);
    echo "fail: expected TypeError, got return\n";
    exit(1);
} catch (\TypeError $e) {
    if (false === str_contains($e->getMessage(), 'not a valid stream filter resource')) {
        echo 'fail: wrong message: ', $e->getMessage(), "\n";
        exit(1);
    }
    echo "ok: TypeError\n";
    exit(0);
}
