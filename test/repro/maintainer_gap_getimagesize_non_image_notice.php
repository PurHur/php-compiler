<?php

declare(strict_types=1);

/**
 * Maintainer repro for #16434 — readable non-image file must return false silently.
 *
 * php-src: ext/standard/image.c php_getimagesize() (no notice for unrecognized format).
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');

$tmp = tempnam(sys_get_temp_dir(), 'img');
if (false === $tmp) {
    echo "fail: tempnam\n";
    exit(1);
}
file_put_contents($tmp, 'not an image');

$result = @getimagesize($tmp);
$last = error_get_last();
unlink($tmp);

if (false !== $result) {
    echo 'fail: expected false, got '.var_export($result, true)."\n";
    exit(1);
}
if (null !== $last) {
    echo 'fail: unexpected warning: '.($last['message'] ?? '')."\n";
    exit(1);
}
echo "ok\n";
