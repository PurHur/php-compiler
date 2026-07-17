--TEST--
SPL ArrayObject/ArrayIterator/SplFixedArray count()/getSize() reject extra args (#20162)
--FILE--
<?php
$ao = new ArrayObject([1, 2, 3]);
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
    } catch (ArgumentCountError $e) {
        echo "$label ", get_class($e), ': ', $e->getMessage(), "\n";
    }
}
echo 'ok_count=', $ao->count(), "\n";
echo 'ok_ai=', $ai->count(), "\n";
echo 'ok_fa=', $fa->count(), ' size=', $fa->getSize(), "\n";
?>
--EXPECT--
ArrayObject::count ArgumentCountError: ArrayObject::count() expects exactly 0 arguments, 1 given
ArrayIterator::count ArgumentCountError: ArrayIterator::count() expects exactly 0 arguments, 1 given
SplFixedArray::count ArgumentCountError: SplFixedArray::count() expects exactly 0 arguments, 1 given
SplFixedArray::getSize ArgumentCountError: SplFixedArray::getSize() expects exactly 0 arguments, 1 given
ok_count=3
ok_ai=3
ok_fa=2 size=2
