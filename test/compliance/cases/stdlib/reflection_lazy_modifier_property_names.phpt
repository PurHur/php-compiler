--TEST--
ReflectionClass::getLazyPropertyNames() — PHP 8.4 lazy modifier (#16954, ext/reflection/php_reflection.c)
--SKIPIF--
<?php
if (getenv('PHP_COMPILER_PROFILE') !== '8.4' && getenv('PHP_COMPILER_PROFILE') !== '8.5') {
    die('skip lazy property modifier requires PHP_COMPILER_PROFILE=8.4');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class LazyDecl {
    public lazy string $a = '1';
    public string $b = '2';
}
$names = (new ReflectionClass(LazyDecl::class))->getLazyPropertyNames();
sort($names);
echo implode(',', $names), "\n";
--EXPECT--
a
