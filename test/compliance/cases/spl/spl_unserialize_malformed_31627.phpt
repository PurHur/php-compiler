--TEST--
SPL unserialize malformed → UnexpectedValueException (#31627, ext/spl)
--FILE--
<?php
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
?>
--EXPECT--
SplObjectStorage UnexpectedValueException:Error at offset 1 of 1 bytes
SplDoublyLinkedList UnexpectedValueException:Error at offset 0 of 1 bytes
SplQueue UnexpectedValueException:Error at offset 0 of 1 bytes
