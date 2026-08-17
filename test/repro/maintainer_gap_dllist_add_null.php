<?php
/**
 * Maintainer gap #31803: SplDoublyLinkedList::add(null, $v) soft-null.
 * Zend: E_DEPRECATED #1 ($index) + insert at 0
 */
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str): bool {
    $name = E_DEPRECATED === $no ? 'E_DEPRECATED' : (string) $no;
    echo "ERR:$name:$str\n";
    return true;
});

$dll = new SplDoublyLinkedList();
$dll->push('a');
$dll->add(null, 'x');
echo 'count=' . $dll->count() . ' top0=' . $dll->offsetGet(0) . "\n";
