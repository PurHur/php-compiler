--TEST--
stdlib SplStack/SplQueue serialize→unserialize preserves elements + order (#23368, ext/spl/spl_dllist.c)
--FILE--
<?php
$stack = new SplStack();
$stack->push('a');
$stack->push('b');
$su = unserialize(serialize($stack));
echo 'stack_count=', count($su), ' stack_top=', $su->top(), ' order=';
$svals = [];
foreach ($su as $v) {
    $svals[] = (string) $v;
}
echo implode(',', $svals), "\n";

$queue = new SplQueue();
$queue->enqueue(10);
$queue->enqueue(20);
$qu = unserialize(serialize($queue));
echo 'queue_count=', count($qu), ' queue_bottom=', $qu->bottom(), ' order=';
$qvals = [];
foreach ($qu as $v) {
    $qvals[] = (string) $v;
}
echo implode(',', $qvals), "\n";

$d = new SplDoublyLinkedList();
$d->push(1);
$d->push(2);
echo 'dllist_count=', count(unserialize(serialize($d))), "\n";
?>
--EXPECT--
stack_count=2 stack_top=b order=b,a
queue_count=2 queue_bottom=10 order=10,20
dllist_count=2
