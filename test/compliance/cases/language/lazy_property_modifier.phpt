--TEST--
language lazy property modifier defers initializer until first read (#16813, Zend/zend_compile.c)
--FILE--
<?php
class LazyHolder {
    public lazy string $x = 'hello';
}
$c = new LazyHolder();
echo $c->x, "\n";
$ref = new ReflectionProperty(LazyHolder::class, 'x');
echo $ref->isLazy($c) ? "lazy\n" : "not-lazy\n";
--EXPECT--
hello
not-lazy
