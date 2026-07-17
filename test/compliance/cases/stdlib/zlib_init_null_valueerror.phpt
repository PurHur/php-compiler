--TEST--
stdlib inflate_init()/deflate_init(null) — ValueError not TypeError (#19915, ext/zlib/zlib.c)
--FILE--
<?php
foreach (['inflate_init', 'deflate_init'] as $func) {
    try {
        $func(null);
        echo "FAIL:$func:no_exception\n";
    } catch (ValueError $e) {
        echo $func.': '.get_class($e).': '.$e->getMessage()."\n";
    }
}
?>
--EXPECT--
inflate_init: ValueError: Encoding mode must be ZLIB_ENCODING_RAW, ZLIB_ENCODING_GZIP or ZLIB_ENCODING_DEFLATE
deflate_init: ValueError: deflate_init(): Argument #1 ($encoding) must be one of ZLIB_ENCODING_RAW, ZLIB_ENCODING_GZIP, or ZLIB_ENCODING_DEFLATE
