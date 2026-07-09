<?php

declare(strict_types=1);

// Minimal 1×1 JPEG via temp file — issue #17455.
$jpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAb/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX/9k=');
$path = sys_get_temp_dir() . '/phpc-getimagesize-jpeg-' . getmypid() . '.jpg';
file_put_contents($path, $jpeg);
$r = getimagesize($path);
@unlink($path);
if (false === $r) {
    echo "false\n";
    exit(1);
}
echo 'ok ' . $r[0] . 'x' . $r[1] . "\n";
