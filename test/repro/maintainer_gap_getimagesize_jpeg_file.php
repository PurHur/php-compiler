<?php

declare(strict_types=1);

$jpeg = base64_decode(
    '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAb/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAGf/9k=',
    true
);
if (false === $jpeg) {
    fwrite(STDERR, "fail: base64 decode\n");
    exit(1);
}

$path = sys_get_temp_dir() . '/phpc-getimagesize-jpeg-' . getmypid() . '.jpg';
file_put_contents($path, $jpeg);
$result = getimagesize($path);
@unlink($path);

if (false === $result) {
    fwrite(STDERR, "fail: false\n");
    exit(1);
}
if (1 !== ($result[0] ?? 0) || 1 !== ($result[1] ?? 0) || 2 !== ($result[2] ?? 0)) {
    fwrite(STDERR, 'fail: dims ' . var_export($result, true) . "\n");
    exit(1);
}
echo 'ok 1x1', "\n";
