<?php

/**
 * Issue #25812 — array_keys(ArrayObject/ArrayIterator::getArrayCopy()) must see the
 * MethodCall EXEC_RETURN HashTable (not null / not the constructor INIT_ARRAY).
 *
 * php-src: ext/spl/spl_array.c — SPL_METHOD(Array, getArrayCopy)
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_keys)
 */
$ao = new ArrayObject(['b' => 2, 'a' => 1]);
var_export(array_keys($ao->getArrayCopy()));
echo "\n";
$c = $ao->getArrayCopy();
var_export(array_keys($c));
echo "\n";
$ait = new ArrayIterator(['b' => 2, 'a' => 1]);
var_export(array_keys($ait->getArrayCopy()));
echo "\n";
var_export(array_keys((new ArrayObject(['b' => 2, 'a' => 1]))->getArrayCopy()));
echo "\n";
var_export(array_values($ao->getArrayCopy()));
echo "\n";
echo count($ao->getArrayCopy()), "\n";
