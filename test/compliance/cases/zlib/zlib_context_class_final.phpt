--TEST--
InflateContext / DeflateContext ReflectionClass::isFinal() (php-src ext/zlib/zlib.stub.php; #28385)
--FILE--
<?php
inflate_init(ZLIB_ENCODING_RAW);
deflate_init(ZLIB_ENCODING_RAW);
echo (new ReflectionClass(InflateContext::class))->isFinal() ? "inflate_final_yes\n" : "inflate_final_no\n";
echo (new ReflectionClass(DeflateContext::class))->isFinal() ? "deflate_final_yes\n" : "deflate_final_no\n";
?>
--EXPECT--
inflate_final_yes
deflate_final_yes
