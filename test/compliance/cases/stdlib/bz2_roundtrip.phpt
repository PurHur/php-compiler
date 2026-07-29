--TEST--
stdlib bzcompress/bzdecompress via VmBz2Core without host ext-bz2 (#3402)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\bz2\Bz2ExtensionPolicy::advertisesExtension()) {
    die('skip bz2 withheld (#11992/#25011)');
}
--ENV--
PHP_COMPILER_ENABLE_BZ2=1
--FILE--
<?php
$plain = str_repeat('abc', 100);
$c = bzcompress($plain, 4);
echo is_string($c) ? '1' : '0';
echo bzdecompress($c) === $plain ? '1' : '0';
echo function_exists('bzcompress') ? '1' : '0';
echo function_exists('bzdecompress') ? '1' : '0';
echo "\n";
?>
--EXPECT--
1111
