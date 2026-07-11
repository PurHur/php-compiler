<?php

declare(strict_types=1);

@getimagesizefromstring('not-image');
$meta = stream_get_meta_data(tmpfile());
$direct = $meta['seekable'];
$foreach = null;
foreach ($meta as $k => $v) {
    if ('seekable' === $k) {
        $foreach = $v;
    }
}

echo 'direct=', var_export($direct, true), "\n";
echo 'foreach=', var_export($foreach, true), "\n";

if (true !== $direct) {
    echo "fail: direct seekable not true\n";
    exit(1);
}
if (true !== $foreach) {
    echo "fail: foreach seekable not true\n";
    exit(1);
}

echo "ok\n";
