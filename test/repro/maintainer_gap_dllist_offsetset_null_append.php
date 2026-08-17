<?php
/**
 * Maintainer gap: SplDoublyLinkedList::offsetSet(null, $v) must append.
 * Zend: appends (null index = push) without TypeError
 * VM: treats null as index 0 and overwrites
 */
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str): bool {
    echo "WARN[$no]: $str\n";
    return true;
});

$dll = new SplDoublyLinkedList();
$dll->push('a');
$dll->push('b');
$dll->offsetSet(null, 'NEW');

$parts = [];
for ($i = 0; $i < $dll->count(); $i++) {
    $parts[] = "[$i]=" . $dll[$i];
}
echo implode(' ', $parts) . ' count=' . $dll->count() . "\n";
