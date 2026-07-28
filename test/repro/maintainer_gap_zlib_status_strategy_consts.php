<?php
declare(strict_types=1);

// #24109 — ZLIB status + strategy constants (ext/zlib/zlib.stub.php)
$status = [
    'ZLIB_OK',
    'ZLIB_STREAM_END',
    'ZLIB_NEED_DICT',
    'ZLIB_ERRNO',
    'ZLIB_STREAM_ERROR',
    'ZLIB_DATA_ERROR',
    'ZLIB_MEM_ERROR',
    'ZLIB_BUF_ERROR',
    'ZLIB_VERSION_ERROR',
];
$strategy = [
    'ZLIB_FILTERED',
    'ZLIB_HUFFMAN_ONLY',
    'ZLIB_RLE',
    'ZLIB_FIXED',
    'ZLIB_DEFAULT_STRATEGY',
];
foreach (array_merge($status, $strategy) as $c) {
    echo $c, "\t", defined($c) ? var_export(constant($c), true) : 'UNDEF', "\n";
}
echo 'encoding_ok=', defined('ZLIB_ENCODING_GZIP') && ZLIB_ENCODING_GZIP === 31 ? '1' : '0', "\n";
