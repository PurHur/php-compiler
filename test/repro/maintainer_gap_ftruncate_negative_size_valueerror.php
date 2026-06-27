<?php

declare(strict_types=1);

// Repro for #12622 — ftruncate() negative size must throw ValueError (php-src ext/standard/streams.c).
$path = sys_get_temp_dir().'/phpc_ftruncate_neg_'.getmypid().'.txt';
$fp = fopen($path, 'w+');
if (false === $fp) {
    echo "fail: fopen\n";
    exit(1);
}
try {
    ftruncate($fp, -1);
    echo "fail: ftruncate(\$stream, -1) succeeded — Zend throws ValueError\n";
    fclose($fp);
    @unlink($path);
    exit(1);
} catch (ValueError $e) {
    fclose($fp);
    @unlink($path);
    if ('ftruncate(): Argument #2 ($size) must be greater than or equal to 0' !== $e->getMessage()) {
        echo 'fail: unexpected message: ', $e->getMessage(), "\n";
        exit(1);
    }
    echo "ok\n";
    exit(0);
}
