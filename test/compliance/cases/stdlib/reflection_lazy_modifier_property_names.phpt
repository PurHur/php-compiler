--TEST--
ReflectionProperty::isLazy for lazy modifier — getLazyPropertyNames phantom (#16954, #28516)
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
echo 'getLazyPropertyNames=', method_exists(ReflectionClass::class, 'getLazyPropertyNames') ? '1' : '0', "\n";
$c = new LazyDecl();
$rp = new ReflectionProperty(LazyDecl::class, 'a');
echo $rp->isLazy($c) ? "lazy\n" : "not-lazy\n";
--EXPECT--
getLazyPropertyNames=0
lazy
