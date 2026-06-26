--TEST--
stdlib bzcompress/bzdecompress via VmBz2Core without host ext-bz2 (#3402)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsBz2()) {
    die('skip bz2 withheld on reference profile (#11992)');
}
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
