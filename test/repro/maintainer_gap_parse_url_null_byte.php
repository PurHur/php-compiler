<?php

declare(strict_types=1);

$url = 'http://example.com/a' . "\0" . 'b';
$path = parse_url($url, PHP_URL_PATH);
if (!\is_string($path)) {
    echo "path:not-string\n";
    exit(1);
}
echo 'strlen:' . \strlen($path) . ':' . \bin2hex($path), "\n";
