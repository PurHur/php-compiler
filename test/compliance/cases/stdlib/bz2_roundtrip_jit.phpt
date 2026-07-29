--TEST--
stdlib bzcompress/bzdecompress JIT roundtrip via VmBz2Core (#16853, ext/bz2/bz2.c)
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
echo extension_loaded('bz2') ? '1' : '0';
echo "\n";
--EXPECT--
111
