<?php

// AOT: ReflectionClass::isIterable matches Zend (#34062).
class Plain {}
class Bag implements IteratorAggregate {
    public function getIterator(): Traversable {
        return new ArrayIterator([]);
    }
}

echo 'P=', (new ReflectionClass(Plain::class))->isIterable() ? '1' : '0', "\n";
echo 'B=', (new ReflectionClass(Bag::class))->isIterable() ? '1' : '0', "\n";
echo 'A=', (new ReflectionClass(ArrayObject::class))->isIterable() ? '1' : '0', "\n";
echo 'I=', (new ReflectionClass(Traversable::class))->isIterable() ? '1' : '0', "\n";
echo 'J=', (new ReflectionClass(Plain::class))->isIterateable() ? '1' : '0', "\n";
