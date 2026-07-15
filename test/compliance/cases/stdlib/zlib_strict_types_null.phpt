--TEST--
stdlib zlib decompress helpers — strict_types call-site TypeError on null (#19119, ext/zlib/zlib.c)
--FILE--
<?php
declare(strict_types=1);

foreach ([
    'gzuncompress' => static fn () => gzuncompress(null),
    'gzdecode' => static fn () => gzdecode(null),
    'gzinflate' => static fn () => gzinflate(null),
    'zlib_encode' => static fn () => zlib_encode(null, ZLIB_ENCODING_GZIP),
    'gzfile' => static fn () => gzfile(null),
    'readgzfile' => static fn () => readgzfile(null),
] as $name => $factory) {
    try {
        $factory();
        echo "$name: uncaught\n";
    } catch (TypeError $e) {
        echo "$name: TypeError\n";
    }
}
?>
--EXPECT--
gzuncompress: TypeError
gzdecode: TypeError
gzinflate: TypeError
zlib_encode: TypeError
gzfile: TypeError
readgzfile: TypeError
