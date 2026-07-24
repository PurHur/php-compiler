--TEST--
ReflectionUnionType::allowsNull only when null is in the union (#22851)
--FILE--
<?php
function a(int|string $x) {}
function b(?int $x) {}
function c(int|string|null $x) {}

foreach (['a', 'b', 'c'] as $fn) {
    $t = (new ReflectionFunction($fn))->getParameters()[0]->getType();
    echo $fn, ' ', $t::class, ' allowsNull=', (int) $t->allowsNull(), "\n";
}
?>
--EXPECT--
a ReflectionUnionType allowsNull=0
b ReflectionNamedType allowsNull=1
c ReflectionUnionType allowsNull=1
