<?php

declare(strict_types=1);

// Issue #12116 — ASCII plain text must sniff as text/plain (php-src libmagic parity).
$hosts = '/etc/hosts';
if (!is_readable($hosts)) {
    $hosts = tempnam(sys_get_temp_dir(), 'phpc_mime_plain_');
    if (false === $hosts) {
        fwrite(STDERR, "tempnam failed\n");
        exit(1);
    }
    file_put_contents($hosts, "127.0.0.1 localhost\n");
    $cleanup = true;
} else {
    $cleanup = false;
}

$mime = mime_content_type($hosts);
if ('text/plain' !== $mime) {
    echo 'mime_bad:', var_export($mime, true), "\n";
    exit(1);
}

echo "ok\n";

if ($cleanup) {
    unlink($hosts);
}
