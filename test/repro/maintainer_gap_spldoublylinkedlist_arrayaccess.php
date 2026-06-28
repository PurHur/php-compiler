<?php

declare(strict_types=1);

// Repro for #13088 — SplDoublyLinkedList ArrayAccess (ext/spl/spl_dllist.c).

$list = new SplDoublyLinkedList();
$list->push(1);
$list->push(2);
if (1 !== $list[0] || 2 !== $list[1]) {
    echo 'fail: bracket read expected 1/2, got ', var_export($list[0], true), '/', var_export($list[1], true), PHP_EOL;
    exit(1);
}
if (!$list->offsetExists(0) || $list->offsetExists(99)) {
    echo 'fail: offsetExists mismatch', PHP_EOL;
    exit(1);
}
$list[1] = 99;
if (99 !== $list[1]) {
    echo 'fail: bracket write expected 99, got ', var_export($list[1], true), PHP_EOL;
    exit(1);
}
unset($list[0]);
if (1 !== $list->count() || 99 !== $list[0]) {
    echo 'fail: offsetUnset expected count=1 and [0]=99', PHP_EOL;
    exit(1);
}
echo 'ok', PHP_EOL;
