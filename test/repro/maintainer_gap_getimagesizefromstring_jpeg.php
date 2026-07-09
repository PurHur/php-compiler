<?php

declare(strict_types=1);

// Minimal 1×1 JPEG accepted by Zend php_getimagesize_from_any() (ext/standard/image.c).
$jpeg = base64_decode(
    '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAb/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAGf/9k=',
    true
);
if (false === $jpeg) {
    fwrite(STDERR, "fail: base64 decode\n");
    exit(1);
}

$result = getimagesizefromstring($jpeg);
if (false === $result) {
    fwrite(STDERR, "fail: false\n");
    exit(1);
}
if (1 !== ($result[0] ?? 0) || 1 !== ($result[1] ?? 0) || 2 !== ($result[2] ?? 0)) {
    fwrite(STDERR, 'fail: dims ' . var_export($result, true) . "\n");
    exit(1);
}
echo 'ok 1x1', "\n";
