--TEST--
zlib ZLIB_OK / ZLIB_STREAM_END / ZLIB_* strategy constants (ext/zlib/zlib.stub.php, #24109)
--FILE--
<?php
declare(strict_types=1);

$want = [
    'ZLIB_OK' => 0,
    'ZLIB_STREAM_END' => 1,
    'ZLIB_NEED_DICT' => 2,
    'ZLIB_ERRNO' => -1,
    'ZLIB_STREAM_ERROR' => -2,
    'ZLIB_DATA_ERROR' => -3,
    'ZLIB_MEM_ERROR' => -4,
    'ZLIB_BUF_ERROR' => -5,
    'ZLIB_VERSION_ERROR' => -6,
    'ZLIB_FILTERED' => 1,
    'ZLIB_HUFFMAN_ONLY' => 2,
    'ZLIB_RLE' => 3,
    'ZLIB_FIXED' => 4,
    'ZLIB_DEFAULT_STRATEGY' => 0,
];
foreach ($want as $name => $value) {
    if (!defined($name)) {
        echo $name, "=undef\n";
        continue;
    }
    echo $name, '=', constant($name) === $value ? 'ok' : 'bad', "\n";
}
echo 'encoding=', defined('ZLIB_ENCODING_GZIP') && ZLIB_ENCODING_GZIP === 31 ? 'ok' : 'bad', "\n";
--EXPECT--
ZLIB_OK=ok
ZLIB_STREAM_END=ok
ZLIB_NEED_DICT=ok
ZLIB_ERRNO=ok
ZLIB_STREAM_ERROR=ok
ZLIB_DATA_ERROR=ok
ZLIB_MEM_ERROR=ok
ZLIB_BUF_ERROR=ok
ZLIB_VERSION_ERROR=ok
ZLIB_FILTERED=ok
ZLIB_HUFFMAN_ONLY=ok
ZLIB_RLE=ok
ZLIB_FIXED=ok
ZLIB_DEFAULT_STRATEGY=ok
encoding=ok
