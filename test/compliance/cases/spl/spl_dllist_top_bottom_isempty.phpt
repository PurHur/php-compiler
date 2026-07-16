--TEST--
SplDoublyLinkedList top/bottom/isEmpty + Queue/Stack inherit (ext/spl/spl_dllist.c; #19761)
--FILE--
<?php
$list = new SplDoublyLinkedList();
var_export($list->isEmpty());
echo "\n";
$list->push(10);
$list->push(20);
$list->unshift(5);
echo $list->top(), ",", $list->bottom(), "\n";
var_export($list->isEmpty());
echo "\n";
try {
    (new SplDoublyLinkedList())->top();
    echo "empty_top_ok\n";
} catch (RuntimeException $e) {
    echo "empty_top:", $e->getMessage(), "\n";
}
try {
    (new SplDoublyLinkedList())->bottom();
    echo "empty_bottom_ok\n";
} catch (RuntimeException $e) {
    echo "empty_bottom:", $e->getMessage(), "\n";
}

$stack = new SplStack();
$stack->push('a');
$stack->push('b');
echo "stack:", $stack->top(), ",", $stack->bottom(), ",", var_export($stack->isEmpty(), true), "\n";

$queue = new SplQueue();
$queue->enqueue('a');
$queue->enqueue('b');
echo "queue:", $queue->top(), ",", $queue->bottom(), ",", var_export($queue->isEmpty(), true), "\n";
?>
--EXPECT--
true
20,5
false
empty_top:Can't peek at an empty datastructure
empty_bottom:Can't peek at an empty datastructure
stack:b,a,false
queue:b,a,false
