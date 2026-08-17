<?php
/**
 * Maintainer gap #31804: SplDoublyLinkedList offsetGet/Unset/Exists(null) soft-null.
 */
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str): bool {
    $name = E_DEPRECATED === $no ? 'E_DEPRECATED' : (string) $no;
    echo "ERR:$name:$str\n";
    return true;
});

$dll = new SplDoublyLinkedList();
$dll->push('a');
$dll->push('b');
echo 'get=' . $dll->offsetGet(null) . "\n";
$exists = $dll->offsetExists(null);
echo 'exists=';
var_export($exists);
echo "\n";
$dll->offsetUnset(null);
echo 'count=' . $dll->count() . ' top0=' . $dll->offsetGet(0) . "\n";
