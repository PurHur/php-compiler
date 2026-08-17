--TEST--
SplDoublyLinkedList::offsetSet(null) appends (#31731)
--FILE--
<?php
error_reporting(E_ALL);
$dll = new SplDoublyLinkedList();
$dll->push('a');
$dll->push('b');
$dll->offsetSet(null, 'NEW');
$parts = [];
for ($i = 0; $i < $dll->count(); $i++) {
    $parts[] = "[$i]=" . $dll[$i];
}
echo implode(' ', $parts) . ' count=' . $dll->count() . "\n";

$dll2 = new SplDoublyLinkedList();
$dll2->push('a');
$dll2->push('b');
$dll2[] = 'X';
$dll2[null] = 'Y';
$parts2 = [];
for ($i = 0; $i < $dll2->count(); $i++) {
    $parts2[] = "[$i]=" . $dll2[$i];
}
echo implode(' ', $parts2) . ' count=' . $dll2->count() . "\n";
?>
--EXPECT--
[0]=a [1]=b [2]=NEW count=3
[0]=a [1]=b [2]=X [3]=Y count=4
