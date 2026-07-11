--TEST--
language lazy property modifier parses and ReflectionProperty::isLazy works (#16813)
--SKIPIF--
<?php
if (getenv('PHP_COMPILER_PROFILE') !== '8.4' && getenv('PHP_COMPILER_PROFILE') !== 'forward') {
    die('skip requires PHP_COMPILER_PROFILE=8.4');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class LazyHolder {
    public lazy string $x = 'hello';
}

$c = new LazyHolder();
$ref = new ReflectionProperty(LazyHolder::class, 'x');
echo $ref->isLazy($c) ? "lazy\n" : "not-lazy\n";
echo $c->x, "\n";
echo $ref->isLazy($c) ? "lazy-after\n" : "initialized\n";
--EXPECT--
lazy
hello
initialized
