<?php
// #20162 — ArrayObject/ArrayIterator/SplFixedArray count()/getSize() arity
$ao = new ArrayObject([1, 2, 3]);
// Construct ArrayIterator directly so AOT can exercise count() without ArrayObject::getIterator().
$ai = new ArrayIterator([1, 2, 3]);
$fa = SplFixedArray::fromArray([1, 2]);
$cases = [
    'ArrayObject::count' => fn () => $ao->count('x'),
    'ArrayIterator::count' => fn () => $ai->count('x'),
    'SplFixedArray::count' => fn () => $fa->count('x'),
    'SplFixedArray::getSize' => fn () => $fa->getSize('x'),
];
foreach ($cases as $label => $fn) {
    try {
        $r = $fn();
        echo "$label COERCED ", var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo "$label ", get_class($e), ': ', $e->getMessage(), "\n";
    }
}
