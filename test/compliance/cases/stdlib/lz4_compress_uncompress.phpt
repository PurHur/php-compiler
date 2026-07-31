--TEST--
lz4_compress()/lz4_uncompress() round-trip (#22529, kjdev/php-ext-lz4)
--ENV--
PHP_COMPILER_ENABLE_LZ4=1
--SKIPIF--
<?php
if (!\PHPCompiler\ext\lz4\Lz4ExtensionPolicy::advertisesExtension()) {
    die('skip lz4 withheld (#25087)');
}
?>
--FILE--
<?php
declare(strict_types=1);

echo function_exists('lz4_compress') ? '1' : '0', "\n";
echo function_exists('lz4_uncompress') ? '1' : '0', "\n";
echo extension_loaded('lz4') ? '1' : '0', "\n";

$blob = lz4_compress('hello');
echo is_string($blob) ? '1' : '0', "\n";
echo lz4_uncompress($blob), "\n";

$empty = lz4_compress('');
echo is_string($empty) ? '1' : '0', "\n";
echo var_export(lz4_uncompress($empty), true), "\n";
--EXPECT--
1
1
1
1
hello
1
''
