<?php

declare(strict_types=1);

// Repro for #13080 — SplDoublyLinkedList::push()/pop() (ext/spl/spl_dllist.c).
$list = new SplDoublyLinkedList();
$list->push(1);
$list->push(2);
$pop = $list->pop();
if (2 !== $pop) {
    echo 'fail: pop expected 2, got ', var_export($pop, true), PHP_EOL;
    exit(1);
}
$list->unshift(3);
$shift = $list->shift();
if (3 !== $shift) {
    echo 'fail: shift expected 3, got ', var_export($shift, true), PHP_EOL;
    exit(1);
}
if (1 !== $list->count()) {
    echo 'fail: count expected 1, got ', $list->count(), PHP_EOL;
    exit(1);
}
echo 'ok', PHP_EOL;
