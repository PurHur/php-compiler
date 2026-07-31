--TEST--
stdlib zstd_compress/zstd_decompress via VmZstdCore pure PHP without libzstd FFI (#6387, #8869)
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
