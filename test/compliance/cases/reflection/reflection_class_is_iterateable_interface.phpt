--TEST--
ReflectionClass::isIterateable() false for Traversable interfaces (#18324, ext/reflection/php_reflection.c)
--FILE--
<?php
declare(strict_types=1);

echo (new ReflectionClass('Iterator'))->isIterateable() ? "iterator_yes\n" : "iterator_no\n";
echo (new ReflectionClass('Traversable'))->isIterateable() ? "traversable_yes\n" : "traversable_no\n";
echo (new ReflectionClass('ArrayObject'))->isIterateable() ? "arrayobject_yes\n" : "arrayobject_no\n";
echo (new ReflectionClass('Generator'))->isIterateable() ? "generator_yes\n" : "generator_no\n";
--EXPECT--
iterator_no
traversable_no
arrayobject_yes
generator_yes
