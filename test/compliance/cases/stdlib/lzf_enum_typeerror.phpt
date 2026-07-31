--TEST--
stdlib lzf_compress() — enum case operand TypeError (#6384, php-src-strict)
--ENV--
PHP_COMPILER_ENABLE_LZF=1
--SKIPIF--
<?php
if (!\PHPCompiler\ext\lzf\LzfExtensionPolicy::advertisesExtension()) {
    die('skip lzf withheld (#25287)');
}
?>
--FILE--
<?php
enum E: string
{
    case X = 'x';
}

try {
    lzf_compress(E::X);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
lzf_compress(): Argument #1 ($data) must be of type string, E given
