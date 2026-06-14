--TEST--
AOT zstd_compress/zstd_decompress round-trip (#8564, ext/zstd/zstd.c)
--FILE--
<?php
echo zstd_decompress(zstd_compress('hello zstd aot')) === 'hello zstd aot' ? '1' : '0';
echo is_string(zstd_compress('x')) ? '1' : '0';
echo "\n";
?>
--EXPECT--
11
