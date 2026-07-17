--TEST--
zlib inflate_get_status()/inflate_get_read_len() after inflate_add (ext/zlib/zlib.c, #20008)
--FILE--
<?php
declare(strict_types=1);
$raw = gzencode('hello');
$ctx = inflate_init(ZLIB_ENCODING_GZIP);
echo 'status0=', inflate_get_status($ctx), "\n";
echo 'read0=', inflate_get_read_len($ctx), "\n";
$out = inflate_add($ctx, $raw, ZLIB_FINISH);
echo 'out=', $out, "\n";
echo 'status=', inflate_get_status($ctx), "\n";
echo 'read=', inflate_get_read_len($ctx), "\n";
try {
    inflate_get_status(1);
} catch (TypeError $e) {
    echo 'bad_int=', $e->getMessage(), "\n";
}
try {
    inflate_get_read_len(deflate_init(ZLIB_ENCODING_RAW));
} catch (TypeError $e) {
    echo 'bad_deflate=', $e->getMessage(), "\n";
}
?>
--EXPECT--
status0=0
read0=0
out=hello
status=1
read=25
bad_int=inflate_get_status(): Argument #1 ($context) must be of type InflateContext, int given
bad_deflate=inflate_get_read_len(): Argument #1 ($context) must be of type InflateContext, DeflateContext given
