<?php

declare(strict_types=1);

// Repro for #13089 — class_implements() on SplFixedArray / SplDoublyLinkedList.

$fa = new SplFixedArray(0);
$faIfaces = class_implements($fa);
foreach (['ArrayAccess', 'Countable', 'IteratorAggregate', 'JsonSerializable'] as $iface) {
    if (!isset($faIfaces[$iface])) {
        echo 'fail: SplFixedArray missing interface ', $iface, PHP_EOL;
        exit(1);
    }
}

$list = new SplDoublyLinkedList();
$listIfaces = class_implements($list);
foreach (['ArrayAccess', 'Countable', 'Iterator', 'Serializable'] as $iface) {
    if (!isset($listIfaces[$iface])) {
        echo 'fail: SplDoublyLinkedList missing interface ', $iface, PHP_EOL;
        exit(1);
    }
}

$ai = new ArrayIterator([]);
if (!isset(class_implements($ai)['ArrayAccess'])) {
    echo 'fail: ArrayIterator baseline missing ArrayAccess', PHP_EOL;
    exit(1);
}

echo 'ok', PHP_EOL;
