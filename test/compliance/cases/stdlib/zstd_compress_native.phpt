--TEST--
stdlib zstd_compress/zstd_decompress via VmZstdNative FFI without host ext-zstd (#6387)
--FILE--
<?php
$plain = 'hello zstd bootstrap';
$z = zstd_compress($plain);
echo is_string($z) ? '1' : '0';
echo zstd_decompress($z) === $plain ? '1' : '0';
echo zstd_uncompress($z) === $plain ? '1' : '0';
echo function_exists('zstd_compress') ? '1' : '0';
echo "\n";
?>
--EXPECT--
1111
