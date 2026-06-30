<?php

declare(strict_types=1);

$failed = 0;

$stack = new SplStack();
$stack->push(1);
$stack->push(2);
$u = unserialize(serialize($stack));
if (count($u) !== 2) {
    echo "SplStack count=", count($u), " expected=2\n";
    ++$failed;
}

$queue = new SplQueue();
$queue->enqueue(10);
$queue->enqueue(20);
$u = unserialize(serialize($queue));
if (count($u) !== 2) {
    echo "SplQueue count=", count($u), " expected=2\n";
    ++$failed;
}

$storage = new SplObjectStorage();
$obj = new stdClass();
$storage->attach($obj, 5);
$u = unserialize(serialize($storage));
if (count($u) !== 1) {
    echo "SplObjectStorage count=", count($u), " expected=1\n";
    ++$failed;
}

exit($failed > 0 ? 1 : 0);
