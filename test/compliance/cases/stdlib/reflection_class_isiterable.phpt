--TEST--
ReflectionClass::isIterable() — Traversable implementors (#22117, ext/reflection/php_reflection.c)
--FILE--
<?php
declare(strict_types=1);

echo (new ReflectionClass('ArrayIterator'))->isIterable() ? "arrayiterator_yes\n" : "arrayiterator_no\n";
echo (new ReflectionClass('Generator'))->isIterable() ? "generator_yes\n" : "generator_no\n";
echo (new ReflectionClass('stdClass'))->isIterable() ? "stdclass_yes\n" : "stdclass_no\n";
echo (new ReflectionClass('Traversable'))->isIterable() ? "traversable_yes\n" : "traversable_no\n";
echo (new ReflectionClass('IteratorAggregate'))->isIterable() ? "iteratoraggregate_yes\n" : "iteratoraggregate_no\n";
echo (new ReflectionClass('ArrayObject'))->isIterable() ? "arrayobject_yes\n" : "arrayobject_no\n";
--EXPECT--
arrayiterator_yes
generator_yes
stdclass_no
traversable_no
iteratoraggregate_no
arrayobject_yes
