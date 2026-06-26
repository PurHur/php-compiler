<?php

declare(strict_types=1);

// Maintainer gap #12302 — is_object() false for stream resources (php-src ext/standard/type.c).

$h = fopen('php://memory', 'r+');
if (false === $h) {
    fwrite(STDERR, "fopen failed\n");
    exit(1);
}
if (is_object($h)) {
    fwrite(STDERR, 'is_object(live stream): expected false, got true'."\n");
    exit(1);
}
if (!is_resource($h)) {
    fwrite(STDERR, "is_resource(live stream): expected true\n");
    exit(1);
}
if ('resource' !== gettype($h)) {
    fwrite(STDERR, 'gettype(live stream): expected resource, got '.gettype($h)."\n");
    exit(1);
}

fclose($h);

if (is_object($h)) {
    fwrite(STDERR, 'is_object(closed stream): expected false, got true'."\n");
    exit(1);
}
if (is_resource($h)) {
    fwrite(STDERR, "is_resource(closed stream): expected false\n");
    exit(1);
}
if ('resource (closed)' !== gettype($h)) {
    fwrite(STDERR, 'gettype(closed stream): expected resource (closed), got '.gettype($h)."\n");
    exit(1);
}

echo "ok\n";
