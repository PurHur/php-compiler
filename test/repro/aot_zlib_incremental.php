<?php
/**
 * #35885 leftover of #4656 — incremental zlib AOT (ext/zlib/zlib.c).
 * php-src: PHP_FUNCTION(deflate_init) / deflate_add / inflate_init / inflate_add
 */
$ctx = deflate_init(ZLIB_ENCODING_DEFLATE);
$bin = deflate_add($ctx, 'hello', ZLIB_FINISH);
echo bin2hex($bin), "\n";
$inf = inflate_init(ZLIB_ENCODING_DEFLATE);
echo inflate_add($inf, $bin, ZLIB_FINISH), "\n";
echo 'status=', inflate_get_status($inf), ' read=', inflate_get_read_len($inf), "\n";
$raw = deflate_init(ZLIB_ENCODING_RAW);
$rawBin = deflate_add($raw, 'hello', ZLIB_FINISH);
$rawInf = inflate_init(ZLIB_ENCODING_RAW);
echo 'raw=', inflate_add($rawInf, $rawBin, ZLIB_FINISH), "\n";
