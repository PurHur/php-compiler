<?php

declare(strict_types=1);

@getimagesizefromstring('not-image');
$meta = stream_get_meta_data(tmpfile());

$assigned = $meta['seekable'];
$nested = var_export($meta['seekable'], true);

echo 'assigned=', var_export($assigned, true), "\n";
echo 'nested=', $nested, "\n";

if (true !== $assigned) {
    echo "fail: assigned\n";
    exit(1);
}
if ('true' !== $nested) {
    echo "fail: nested var_export\n";
    exit(1);
}

echo "ok\n";
