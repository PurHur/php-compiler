<?php

declare(strict_types=1);

// #22117 — ReflectionClass::isIterable() vs Zend (ext/reflection/php_reflection.c).

var_export((new ReflectionClass(ArrayIterator::class))->isIterable());
echo "\n";
var_export((new ReflectionClass(Generator::class))->isIterable());
echo "\n";
var_export((new ReflectionClass(stdClass::class))->isIterable());
echo "\n";
var_export((new ReflectionClass(Traversable::class))->isIterable());
echo "\n";
var_export((new ReflectionClass(IteratorAggregate::class))->isIterable());
echo "\n";
