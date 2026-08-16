<?php
// SplDoublyLinkedList OOB offset* must throw OutOfRangeException (not LogicException).
error_reporting(E_ALL);
$l = new SplDoublyLinkedList();
$l->push(1);
foreach (['offsetGet' => 5, 'offsetSet' => [5, 9], 'offsetUnset' => 5] as $method => $arg) {
    try {
        if ($method === 'offsetSet') {
            $l->offsetSet($arg[0], $arg[1]);
        } elseif ($method === 'offsetUnset') {
            $l->offsetUnset($arg);
        } else {
            $l->offsetGet($arg);
        }
        echo "$method ok\n";
    } catch (Throwable $e) {
        echo $method, ' ', get_class($e), ': ', $e->getMessage(), "\n";
        echo $method, ' instanceof OutOfRangeException=', $e instanceof OutOfRangeException ? '1' : '0', "\n";
    }
}
