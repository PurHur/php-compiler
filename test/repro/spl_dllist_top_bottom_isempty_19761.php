<?php
// Repro #19761 — SplDoublyLinkedList top/bottom/isEmpty (+ Queue/Stack inherit)
$list = new SplDoublyLinkedList();
$list->push(10);
$list->push(20);
$list->unshift(5);
echo $list->top(), ',', $list->bottom(), ',', var_export($list->isEmpty(), true), "\n";

$queue = new SplQueue();
$queue->enqueue('a');
$queue->enqueue('b');
echo $queue->top(), ',', $queue->bottom(), "\n";

try {
    (new SplDoublyLinkedList())->top();
    echo "fail_empty\n";
} catch (RuntimeException $e) {
    echo 'empty_ok', "\n";
}
