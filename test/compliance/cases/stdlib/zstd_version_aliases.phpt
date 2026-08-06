--TEST--
stdlib ZSTD_VERSION_* + Zstd\compress aliases (#28079, kjdev/php-ext-zstd)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\zstd\ZstdExtensionPolicy::advertisesExtension()) {
    die('skip zstd not advertised');
}
--ENV--
PHP_COMPILER_ENABLE_ZSTD=1
--FILE--
<?php
echo defined('ZSTD_VERSION_TEXT') ? '1' : '0';
echo "\n";
echo defined('ZSTD_VERSION_NUMBER') ? '1' : '0';
echo "\n";
echo function_exists('Zstd\\compress') ? '1' : '0';
echo "\n";
echo function_exists('Zstd\\uncompress') ? '1' : '0';
echo "\n";
$plain = 'ns-zstd';
$c = \Zstd\compress($plain);
echo (\Zstd\uncompress($c) === $plain) ? '1' : '0';
echo "\n";
?>
--EXPECT--
1
1
1
1
1
