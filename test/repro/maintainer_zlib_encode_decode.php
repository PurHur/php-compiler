<?php
if (!function_exists('zlib_encode')) {
    fwrite(STDERR, "zlib_encode: no\n");
    exit(1);
}
if (!function_exists('zlib_decode')) {
    fwrite(STDERR, "zlib_decode: no\n");
    exit(1);
}
$raw = 'hello zlib';
$enc = zlib_encode($raw, ZLIB_ENCODING_RAW);
$dec = zlib_decode($enc);
var_export($dec === $raw);
echo "\n";
