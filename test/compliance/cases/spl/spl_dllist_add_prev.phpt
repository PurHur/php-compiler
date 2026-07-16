--TEST--
SplDoublyLinkedList add()/prev() (ext/spl/spl_dllist.c; #19762)
--FILE--
<?php
$d = new SplDoublyLinkedList();
$d->push(1);
$d->push(2);
$d->push(3);
$d->add(1, 99);
$out = [];
foreach ($d as $k => $v) {
    $out[] = $k.'='.$v;
}
echo implode(';', $out), "\n";

$d->add(0, 0);
$end = $d->count();
$d->add($end, 4);
$out = [];
foreach ($d as $k => $v) {
    $out[] = $k.'='.$v;
}
echo implode(';', $out), "\n";

try {
    $d->add(99, 1);
    echo "oor_ok\n";
} catch (LogicException $e) {
    echo "oor:", $e->getMessage(), "\n";
}

$d2 = new SplDoublyLinkedList();
$d2->push('a');
$d2->push('b');
$d2->push('c');
$d2->rewind();
$d2->next();
$d2->prev();
echo $d2->key(), ',', $d2->current(), "\n";
$d2->rewind();
$d2->prev();
var_export($d2->valid());
echo "\n";
?>
--EXPECT--
0=1;1=99;2=2;3=3
0=0;1=1;2=99;3=2;4=3;5=4
oor:SplDoublyLinkedList::add(): Argument #1 ($index) is out of range
0,a
false
