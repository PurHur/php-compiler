--TEST--
SplDoublyLinkedList / SplQueue residual excess argc (#30964)
--FILE--
<?php
$l = new SplDoublyLinkedList();
$l->push(1);
$l->push(2);
foreach ([
    ['push', static fn ($o) => $o->push(3, 1)],
    ['unshift', static fn ($o) => $o->unshift(0, 1)],
    ['isEmpty', static fn ($o) => $o->isEmpty(1)],
    ['bottom', static fn ($o) => $o->bottom(1)],
    ['add', static fn ($o) => $o->add(1, 9, 1)],
    ['setIteratorMode', static fn ($o) => $o->setIteratorMode(0, 1)],
    ['getIteratorMode', static fn ($o) => $o->getIteratorMode(1)],
    ['offsetExists', static fn ($o) => $o->offsetExists(0, 1)],
    ['offsetGet', static fn ($o) => $o->offsetGet(0, 1)],
    ['offsetSet', static fn ($o) => $o->offsetSet(0, 9, 1)],
    ['offsetUnset', static fn ($o) => $o->offsetUnset(0, 1)],
] as [$name, $fn]) {
    try {
        $fn($l);
        echo "$name COERCED\n";
    } catch (ArgumentCountError $e) {
        echo $name, ' ', $e->getMessage(), "\n";
    }
}
$q = new SplQueue();
$q->enqueue(1);
try {
    $q->enqueue(2, 1);
    echo "enqueue COERCED\n";
} catch (ArgumentCountError $e) {
    echo 'enqueue ', $e->getMessage(), "\n";
}
try {
    $q->dequeue(1);
    echo "dequeue COERCED\n";
} catch (ArgumentCountError $e) {
    echo 'dequeue ', $e->getMessage(), "\n";
}
$l->push(3);
echo 'push_ok=', $l->count(), "\n";
echo 'isEmpty_ok=', $l->isEmpty() ? '1' : '0', "\n";
echo 'bottom_ok=', $l->bottom(), "\n";
$q->enqueue(9);
echo 'enqueue_ok=', $q->count(), "\n";
?>
--EXPECT--
push SplDoublyLinkedList::push() expects exactly 1 argument, 2 given
unshift SplDoublyLinkedList::unshift() expects exactly 1 argument, 2 given
isEmpty SplDoublyLinkedList::isEmpty() expects exactly 0 arguments, 1 given
bottom SplDoublyLinkedList::bottom() expects exactly 0 arguments, 1 given
add SplDoublyLinkedList::add() expects exactly 2 arguments, 3 given
setIteratorMode SplDoublyLinkedList::setIteratorMode() expects exactly 1 argument, 2 given
getIteratorMode SplDoublyLinkedList::getIteratorMode() expects exactly 0 arguments, 1 given
offsetExists SplDoublyLinkedList::offsetExists() expects exactly 1 argument, 2 given
offsetGet SplDoublyLinkedList::offsetGet() expects exactly 1 argument, 2 given
offsetSet SplDoublyLinkedList::offsetSet() expects exactly 2 arguments, 3 given
offsetUnset SplDoublyLinkedList::offsetUnset() expects exactly 1 argument, 2 given
enqueue SplQueue::enqueue() expects exactly 1 argument, 2 given
dequeue SplQueue::dequeue() expects exactly 0 arguments, 1 given
push_ok=3
isEmpty_ok=0
bottom_ok=1
enqueue_ok=2
