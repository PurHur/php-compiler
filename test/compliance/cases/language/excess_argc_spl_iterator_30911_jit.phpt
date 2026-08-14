--TEST--
language: ArrayIterator/SplStack excess argc → ArgumentCountError JIT (#30911, spl_array.c/spl_dllist.c)
--FILE--
<?php
$a = new ArrayIterator([1, 2, 3]);
foreach (['current', 'key', 'next', 'rewind', 'valid', 'count', 'serialize'] as $m) {
    try {
        $a->$m(1);
        echo $m, " ACCEPTED\n";
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    }
}
$s = new SplStack();
$s->push(1);
foreach (['top', 'pop', 'count'] as $m) {
    try {
        $s->$m(1);
        echo $m, " ACCEPTED\n";
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    }
}
$a->rewind();
echo 'ok=', $a->current(), ',', $s->top(), "\n";
?>
--EXPECT--
ArrayIterator::current() expects exactly 0 arguments, 1 given
ArrayIterator::key() expects exactly 0 arguments, 1 given
ArrayIterator::next() expects exactly 0 arguments, 1 given
ArrayIterator::rewind() expects exactly 0 arguments, 1 given
ArrayIterator::valid() expects exactly 0 arguments, 1 given
ArrayIterator::count() expects exactly 0 arguments, 1 given
ArrayIterator::serialize() expects exactly 0 arguments, 1 given
SplDoublyLinkedList::top() expects exactly 0 arguments, 1 given
SplDoublyLinkedList::pop() expects exactly 0 arguments, 1 given
SplDoublyLinkedList::count() expects exactly 0 arguments, 1 given
ok=1,1
