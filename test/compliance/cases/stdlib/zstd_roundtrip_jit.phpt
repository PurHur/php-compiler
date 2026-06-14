--TEST--
stdlib zstd_compress/zstd_decompress JIT round-trip (#6387, #8564, ext/zstd/zstd.c)
--FILE--
<?php
$plain = 'hello zstd bootstrap';
$z = zstd_compress($plain);
echo is_string($z) ? '1' : '0';
echo zstd_decompress($z) === $plain ? '1' : '0';
echo zstd_uncompress($z) === $plain ? '1' : '0';
echo "\n";
?>
--EXPECT--
111
