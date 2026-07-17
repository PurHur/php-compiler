--TEST--
stdlib inflate_add()/deflate_add(null data) strict_types — TypeError (#19945)
--FILE--
<?php
declare(strict_types=1);

$ictx = inflate_init(ZLIB_ENCODING_RAW);
try {
    inflate_add($ictx, null);
    echo "inflate_add: uncaught\n";
} catch (TypeError $e) {
    echo "inflate_add: TypeError\n";
}

$dctx = deflate_init(ZLIB_ENCODING_RAW);
try {
    deflate_add($dctx, null);
    echo "deflate_add: uncaught\n";
} catch (TypeError $e) {
    echo "deflate_add: TypeError\n";
}

$dctx2 = deflate_init(ZLIB_ENCODING_RAW);
$compressed = deflate_add($dctx2, "hello", ZLIB_FINISH);
$ictx2 = inflate_init(ZLIB_ENCODING_RAW);
$decompressed = inflate_add($ictx2, $compressed, ZLIB_FINISH);
echo "roundtrip: $decompressed\n";
?>
--EXPECT--
inflate_add: TypeError
deflate_add: TypeError
roundtrip: hello
