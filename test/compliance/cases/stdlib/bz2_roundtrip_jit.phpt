--TEST--
stdlib bzcompress/bzdecompress JIT roundtrip via VmBz2Core (#16853, ext/bz2/bz2.c)
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
echo extension_loaded('bz2') ? '1' : '0';
echo "\n";
--EXPECT--
111
