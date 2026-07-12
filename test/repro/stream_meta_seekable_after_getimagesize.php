<?php

declare(strict_types=1);

$m = stream_get_meta_data(tmpfile());
@getimagesizefromstring('not-image');
if (true !== ($m['seekable'] ?? null)) {
    echo "fail: seekable offset read after @ getimagesizefromstring\n";
    exit(1);
}

foreach ($m as $key => $value) {
    if ('seekable' === $key && true !== $value) {
        echo "fail: seekable foreach after @ getimagesizefromstring\n";
        exit(1);
    }
}

echo "ok\n";
