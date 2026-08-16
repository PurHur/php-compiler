<?php
/** Maintainer gap: SplObjectStorage/SplDoublyLinkedList/SplQueue::unserialize malformed → UnexpectedValueException. */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$cases = [
    'SplObjectStorage' => [new SplObjectStorage(), 'x'],
    'SplDoublyLinkedList' => [new SplDoublyLinkedList(), 'x'],
    'SplQueue' => [new SplQueue(), 'x'],
];

foreach ($cases as $label => [$obj, $payload]) {
    try {
        $r = $obj->unserialize($payload);
        echo $label, ' ret=';
        var_export($r);
        echo "\n";
    } catch (Throwable $e) {
        echo $label, ' ', get_class($e), ':', $e->getMessage(), "\n";
    }
}
