--TEST--
stdlib SplStack/SplQueue/object storage serialize() round-trip (#14164, ext/spl/spl_dllist.c)
--FILE--
<?php
declare(strict_types=1);
$stack = new SplStack();
$stack->push(1);
$stack->push(2);
echo count(unserialize(serialize($stack))), "\n";

$queue = new SplQueue();
$queue->enqueue(10);
$queue->enqueue(20);
echo count(unserialize(serialize($queue))), "\n";

$storage = new SplObjectStorage();
$storage->attach(new stdClass(), 5);
echo count(unserialize(serialize($storage))), "\n";
?>
--EXPECT--
2
2
1
