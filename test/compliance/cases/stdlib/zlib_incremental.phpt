--TEST--
stdlib zlib incremental deflate/inflate round-trip (#4656, ext/zlib/zlib.c)
--FILE--
<?php
declare(strict_types=1);

$ctx = deflate_init(ZLIB_ENCODING_RAW);
$out = deflate_add($ctx, 'hello', ZLIB_FINISH);
$in = inflate_init(ZLIB_ENCODING_RAW);
$plain = inflate_add($in, $out, ZLIB_FINISH);
echo $plain, "\n";
echo function_exists('deflate_init') ? "yes\n" : "no\n";
echo class_exists('DeflateContext') ? "yes\n" : "no\n";
--EXPECT--
hello
yes
yes
