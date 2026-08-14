--TEST--
ArrayIterator / RecursiveArrayIterator residual excess argc JIT (#30963, spl_array.c)
--FILE--
<?php
$a = new ArrayIterator([1, 2, 3]);
foreach ([
    ['seek', static fn ($o) => $o->seek(0, 1)],
    ['getArrayCopy', static fn ($o) => $o->getArrayCopy(1)],
    ['uasort', static fn ($o) => $o->uasort(static fn ($x, $y) => $x <=> $y, 1)],
    ['uksort', static fn ($o) => $o->uksort(static fn ($x, $y) => $x <=> $y, 1)],
    ['getFlags', static fn ($o) => $o->getFlags(1)],
    ['setFlags', static fn ($o) => $o->setFlags(0, 1)],
    ['offsetExists', static fn ($o) => $o->offsetExists(0, 1)],
    ['offsetGet', static fn ($o) => $o->offsetGet(0, 1)],
    ['offsetUnset', static fn ($o) => $o->offsetUnset(0, 1)],
    ['offsetSet', static fn ($o) => $o->offsetSet('c', 3, 1)],
    ['append', static fn ($o) => $o->append(9, 1)],
] as [$name, $fn]) {
    try {
        $fn($a);
        echo "$name COERCED\n";
    } catch (ArgumentCountError $e) {
        echo $name, ' ', $e->getMessage(), "\n";
    }
}
$r = new RecursiveArrayIterator([1, [2]]);
try {
    $r->hasChildren(1);
    echo "hasChildren COERCED\n";
} catch (ArgumentCountError $e) {
    echo 'hasChildren ', $e->getMessage(), "\n";
}
$a->seek(0);
echo 'seek_ok=', $a->current(), "\n";
?>
--EXPECT--
seek ArrayIterator::seek() expects exactly 1 argument, 2 given
getArrayCopy ArrayIterator::getArrayCopy() expects exactly 0 arguments, 1 given
uasort ArrayIterator::uasort() expects exactly 1 argument, 2 given
uksort ArrayIterator::uksort() expects exactly 1 argument, 2 given
getFlags ArrayIterator::getFlags() expects exactly 0 arguments, 1 given
setFlags ArrayIterator::setFlags() expects exactly 1 argument, 2 given
offsetExists ArrayIterator::offsetExists() expects exactly 1 argument, 2 given
offsetGet ArrayIterator::offsetGet() expects exactly 1 argument, 2 given
offsetUnset ArrayIterator::offsetUnset() expects exactly 1 argument, 2 given
offsetSet ArrayIterator::offsetSet() expects exactly 2 arguments, 3 given
append ArrayIterator::append() expects exactly 1 argument, 2 given
hasChildren RecursiveArrayIterator::hasChildren() expects exactly 0 arguments, 1 given
seek_ok=1
