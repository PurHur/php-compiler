<?php

declare(strict_types=1);

// Minimal 1×1 JPEG accepted by Zend getimagesizefromstring() — issue #17455.
$jpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAb/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX/9k=');
$r = getimagesizefromstring($jpeg);
if (false === $r) {
    echo "false\n";
    exit(1);
}
echo 'ok ' . $r[0] . 'x' . $r[1] . "\n";
