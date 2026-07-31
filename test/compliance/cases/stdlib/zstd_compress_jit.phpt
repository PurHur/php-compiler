--TEST--
stdlib zstd_compress/zstd_decompress JIT round-trip (#8564)
--ENV--
PHP_COMPILER_ENABLE_ZSTD=1
--SKIPIF--
<?php
if (!\PHPCompiler\ext\zstd\ZstdExtensionPolicy::advertisesExtension()) {
    die('skip zstd withheld (#25287)');
}
?>
--FILE--
<?php
$plain = 'hello zstd jit';
$z = zstd_compress($plain);
echo is_string($z) ? '1' : '0';
echo zstd_decompress($z) === $plain ? '1' : '0';
echo zstd_uncompress($z) === $plain ? '1' : '0';
echo "\n";
?>
--EXPECT--
111
